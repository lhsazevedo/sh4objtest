<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

use Lhsazevedo\Sh4ObjTest\ObjectParser;
use Lhsazevedo\Sh4ObjTest\Parser\ParsedObject;
use Lhsazevedo\Sh4ObjTest\Simulator\Exceptions\ExpectationException;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\CallCommand;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\ReturnExpectation;
use Symfony\Component\Console\Output\OutputInterface;
use Lhsazevedo\Sh4ObjTest\TestCase;

readonly class ObjectResult {
    private CoverageTracker $coverage;

    public function __construct(
        public string $objectFile,
    ) {
        $this->coverage = new CoverageTracker();
    }

    public function mergeCoverage(CoverageTracker $coverage): void {
        $this->coverage->merge($coverage);
    }

    /**
     * @return array<int, array{covered: int, total: int, uncoveredLines: int[], coveredLines: int[]}>
     */
    public function getReport(ParsedObject $object): array {
        return $this->coverage->getReport($object);
    }

    /**
     * @return array{name: string, touched: bool}[]
     */
    public function getSymbolReport(ParsedObject $object): array {
        return $this->coverage->getSymbolReport($object);
    }
}

class Runner
{
    public function __construct(
        private OutputInterface $output,
        private bool $shouldOutputDisasm = false,
        private bool $shouldTrackCoverage = false,
        private ?string $coverageJsonPath = null,
        private bool $coverageFull = false,
    )
    {}

    public function runFile(string $testFile, string $objectFile): FileResult
    {
        $testCase = require $testFile;
        $reflectedBaseTestCase = new \ReflectionClass(TestCase::class);
        $reflectedTestCase = new \ReflectionClass($testCase);

        // TODO: Check if it is really necessary to
        // pass the parsed object to the test case.
        $parsedObject = ObjectParser::parse($objectFile);
        $linkedCode = $this->linkObject($parsedObject);
        $result = new FileResult();

        $this->output->writeln("◯ {$testFile}");

        try {
            foreach ($reflectedTestCase->getMethods() as $reflectionMethod) {
                if (!$reflectionMethod->isPublic()) {
                    continue;
                }

                if (!str_starts_with($reflectionMethod->name, 'test')) {
                    continue;
                }

                //$this->output->writeln("{$testFile}...");
                // $this->output->writeln("{$reflectionMethod->name}...");
                /** @var TestCase */
                $currentTestCase = require $testFile;
                $currentTestCase->setParsedObject($parsedObject);
                $currentTestCase->setObjectFile($objectFile);
                call_user_func([$currentTestCase, $reflectionMethod->name]);

                $expectations = $reflectedBaseTestCase
                    ->getProperty('expectations')
                    ->getValue($currentTestCase);

                /** @var Entry */
                $entry = $reflectedBaseTestCase->getProperty('entry')
                    ->getValue($currentTestCase);

                if ($entry->symbol) {
                    $callCommand = new CallCommand($entry->symbol);
                    // TODO: Rename to arguments
                    $callCommand->arguments = $entry->parameters;
                    array_unshift($expectations, $callCommand);

                    if ($entry->return !== null || $entry->floatReturn !== null) {
                        $expectations[] = new ReturnExpectation(
                            $entry->return ?? $entry->floatReturn,
                        );
                    }
                }

                $testRelocations = $reflectedBaseTestCase->getProperty('testRelocations')->getValue($currentTestCase);
                // TODO: Check if it's necessary to link on every test. Only the
                // external resolution depends on the per-test relocations; the
                // internal relocations and exports could be linked once per object.
                $linkedProgram = (new Linker())->link($parsedObject, $linkedCode, $testRelocations);

                $testCaseDto = new TestCaseDTO(
                    name: $reflectionMethod->name,
                    objectFile: $objectFile,
                    linkedProgram: $linkedProgram,
                    initializations: $reflectedBaseTestCase->getProperty('initializations')->getValue($currentTestCase),
                    testRelocations: $testRelocations,
                    expectations: $expectations,
                    defaultCallbacks: $reflectedBaseTestCase->getProperty('defaultCallbacks')->getValue($currentTestCase),
                    defaultConventions: $reflectedBaseTestCase->getProperty('defaultConventions')->getValue($currentTestCase),
                    // entry: $reflectedBaseTestCase->getProperty('entry')->getValue($currentTestCase),
                    shouldRandomizeMemory: $reflectedBaseTestCase->getProperty('randomizeMemory')->getValue($currentTestCase),
                    shouldStopWhenFulfilled: $reflectedBaseTestCase->getProperty('forceStop')->getValue($currentTestCase),
                );

                $run = new Run(
                    $this->output,
                    $testCaseDto,
                    $this->shouldOutputDisasm,
                );

                $result->addRun($run->run());
            }
        } catch (ExpectationException $e) {
            $this->output->writeln("\n<bg=red> FAILED EXPECTATION </> <fg=red>{$e->getMessage()}</>\n");
            // TODO: Add failed expectation to result
            exit;
        }

        foreach ($result->getRuns() as $run) {
            $result->coverage->merge($run->coverage);
        }

        return $result;
    }

    public function runSuite(string $suiteFile, ?string $testCaseFilter): bool
    {
        $suite = require $suiteFile;
        $suiteDir = dirname($suiteFile);

        /** @var ObjectResult[] */
        $objectResults = [];

        $success = true;
        foreach ($suite['groups'] as $group) {
            foreach ($group['objects'] as $object) {
                foreach($group['tests'] as $test) {
                    $filePath = realpath("$suiteDir/$test");
                    // TODO: Filter files before running tests, and error if no files are found.
                    if ($testCaseFilter && !str_starts_with($filePath, $testCaseFilter)) {
                        continue;
                    }

                    $objectPath = realpath("$suiteDir/$object");
                    $fileResult = $this->runFile($filePath, $objectPath);
                    $objectResults[$objectPath] ??= new ObjectResult($objectPath);
                    $objectResults[$objectPath]->mergeCoverage($fileResult->coverage);
                }
            }
        }

        if ($this->shouldTrackCoverage) {
            $coverageData = $this->collectCoverage($objectResults);
            $this->renderCoverage($coverageData);

            if ($this->coverageJsonPath !== null) {
                file_put_contents(
                    $this->coverageJsonPath,
                    json_encode($coverageData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
                );
                $this->output->writeln('');
                $this->output->writeln("  <info>Wrote coverage report to {$this->coverageJsonPath}</info>");
            }
        }

        // TODO: Return failure as well
        return true;
    }

    /**
     * Build the full structured coverage report, shared by the terminal view
     * and the JSON dump.
     *
     * @param array<string, ObjectResult> $objectResults
     * @return array<string, array{
     *     files: array<array{name: string, path: ?string, covered: int, total: int, percentage: float, uncoveredRanges: string[]}>,
     *     variables: array{name: string, touched: bool}[]
     * }>
     */
    private function collectCoverage(array $objectResults): array
    {
        $coverageData = [];

        foreach ($objectResults as $objectPath => $objResult) {
            $parsedObject = ObjectParser::parse($objectPath);
            // Link so sections get their runtime linkedAddress
            $this->linkObject($parsedObject);
            $report = $objResult->getReport($parsedObject);

            $files = [];
            foreach ($report as $fileNumber => $fileData) {
                sort($fileData['uncoveredLines']);
                $path = $parsedObject->unit->sourceFiles[$fileNumber] ?? null;

                $files[] = [
                    'name' => $path !== null ? basename($path) : "file $fileNumber",
                    'path' => $path,
                    'covered' => $fileData['covered'],
                    'total' => $fileData['total'],
                    'percentage' => $fileData['covered'] / $fileData['total'] * 100,
                    'uncoveredRanges' => $this->computeLineRanges($fileData['uncoveredLines'], $fileData['coveredLines']),
                ];
            }

            $coverageData[$objectPath] = [
                'files' => $files,
                'variables' => $objResult->getSymbolReport($parsedObject),
            ];
        }

        return $coverageData;
    }

    /** @param array<string, array{files: array<array<string, mixed>>, variables: array{name: string, touched: bool}[]}> $coverageData */
    private function renderCoverage(array $coverageData): void
    {
        $this->output->writeln('');
        $this->output->writeln('<info>Coverage:</info>');

        // Width the percentage column against every file so it right-aligns
        // across all objects, not just within one.
        $names = array_merge(...array_map(
            fn ($data) => array_map(fn ($f) => $f['name'], $data['files']),
            array_values($coverageData),
        ));
        $nameWidth = $names === [] ? 0 : max(array_map('strlen', $names));

        foreach ($coverageData as $objectPath => $data) {
            $this->output->writeln("  <comment>{$objectPath}</comment>");

            if ($data['files'] === []) {
                $this->output->writeln('    (no debug line info)');
                continue;
            }

            foreach ($data['files'] as $file) {
                $pct = sprintf('%6.2f%%', $file['percentage']);
                $color = $file['percentage'] >= 100 ? 'green' : ($file['percentage'] >= 50 ? 'yellow' : 'red');
                $ranges = $this->coverageFull
                    ? implode(', ', $file['uncoveredRanges'])
                    : $this->truncateRanges($file['uncoveredRanges'], 4);

                $this->output->writeln(sprintf(
                    '    %-' . $nameWidth . "s  <fg=%s>%s</>  %s",
                    $file['name'],
                    $color,
                    $pct,
                    $ranges,
                ));
            }

            $variables = $data['variables'];
            if ($variables !== []) {
                $untouched = array_values(array_filter($variables, fn ($s) => !$s['touched']));

                $this->output->writeln(sprintf(
                    '    %d/%d variables accessed',
                    count($variables) - count($untouched),
                    count($variables),
                ));

                if ($this->coverageFull && $untouched !== []) {
                    $names = array_map(fn ($s) => $s['name'], $untouched);
                    $this->output->writeln('      <fg=red>unaccessed:</> ' . implode(', ', $names));
                }
            }
        }
    }

    /**
     * Render uncovered ranges, keeping only the first few so the line stays
     * tidy; the full list lives in the JSON report.
     *
     * @param string[] $ranges
     */
    private function truncateRanges(array $ranges, int $max): string
    {
        if ($ranges === []) {
            return '';
        }

        if (count($ranges) <= $max) {
            return implode(', ', $ranges);
        }

        $shown = array_slice($ranges, 0, $max);
        return implode(', ', $shown) . sprintf(' (+%d more)', count($ranges) - $max);
    }

    /**
     * Collapse line numbers into ranges, bridging gaps that contain no covered
     * line so e.g. 5-8 and 10-15 collapse to 5-15 when line 9 has no debug
     * record (blank line, brace, declaration).
     *
     * @param int[] $lines        uncovered line numbers, sorted ascending
     * @param int[] $coveredLines covered line numbers
     * @return string[]
     */
    private function computeLineRanges(array $lines, array $coveredLines = []): array
    {
        $covered = array_flip($coveredLines);

        $ranges = [];
        $start = $end = null;

        foreach ($lines as $line) {
            if ($start === null) {
                $start = $end = $line;
            } elseif ($this->gapIsEmpty($end, $line, $covered)) {
                $end = $line;
            } else {
                $ranges[] = $start === $end ? (string)$start : "{$start}-{$end}";
                $start = $end = $line;
            }
        }

        if ($start !== null) {
            $ranges[] = $start === $end ? (string)$start : "{$start}-{$end}";
        }

        return $ranges;
    }

    /** @param array<int,int> $covered covered line numbers as a set */
    private function gapIsEmpty(int $from, int $to, array $covered): bool
    {
        for ($line = $from + 1; $line < $to; $line++) {
            if (isset($covered[$line])) {
                return false;
            }
        }

        return true;
    }

    protected function linkObject(ParsedObject $object): string {
        $linkedCode = '';
        // TODO: Handle multiple units?
        foreach ($object->unit->sections as $section) {
            // Align
            $remainder = strlen($linkedCode) % $section->alignment;
            if ($remainder) {
                $linkedCode .= str_repeat("\0", $section->alignment - $remainder);
            }

            $section->rellocate(strlen($linkedCode));

            $linkedCode .= $section->assembleObjectData();
        }

        return $linkedCode;
    }
}
