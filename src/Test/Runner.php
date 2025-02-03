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
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableCellStyle;

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

    public function getCoverage(ParsedObject $object): float {
        return $this->coverage->getCoverage($object);
    }
}

class Runner
{
    public function __construct(
        private OutputInterface $output,
        private bool $shouldOutputDisasm = false,
        private bool $shouldTrackCoverage = false,
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

                $testCaseDto = new TestCaseDTO(
                    name: $reflectionMethod->name,
                    objectFile: $objectFile,
                    parsedObject: $parsedObject,
                    initializations: $reflectedBaseTestCase->getProperty('initializations')->getValue($currentTestCase),
                    testRelocations: $reflectedBaseTestCase->getProperty('testRelocations')->getValue($currentTestCase),
                    expectations: $expectations,
                    // entry: $reflectedBaseTestCase->getProperty('entry')->getValue($currentTestCase),
                    linkedCode: $linkedCode,
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

        /** @var array<string, FileResult[]> */
        $fileResults = [];

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
                    $fileResults[$objectPath] = $fileResult;
                    $objectResults[$objectPath] ??= new ObjectResult($objectPath);
                    $objectResults[$objectPath]->mergeCoverage($fileResult->coverage);
                }
            }
        }

        if ($this->shouldTrackCoverage) {
            $this->output->writeln("Processing coverage:");
            $table = new Table($this->output);
            $table->setHeaders(['Object', 'Coverage']);
            foreach ($objectResults as $objectPath => $objectResults) {
                // $coverage = new CoverageTracker();
                // $coverage->merge($objectResults->coverage);
                // CONTINUE: Need to pass object here but object is only available inside runFile...
                $parsedObject = ObjectParser::parse($objectPath);
                $table->addRow([
                    $objectPath,
                    new TableCell(
                        sprintf("%.2f%%", $objectResults->getCoverage($parsedObject) * 100),
                        [
                            'style' => new TableCellStyle(['align' => 'right']),
                        ]
                    ),
                ]);
            }
            $table->render();

            // foreach ($fileResults as $object => $testCaseResult) {
            //     $this->output->writeln("{$object}:");
            //     $table2 = new Table($this->output);
            //     $table2->setHeaders(['Address', 'R', 'W', 'X']);
            //     $report = $testCaseResult->coverage->getReport($parsedObject);
            //     foreach ($report as $address => $accesses) {
            //         $table2->addRow([
            //             sprintf("0x%08x", $address),
            //             $accesses[0] ? 'X' : ' ',
            //             $accesses[1] ? 'X' : ' ',
            //             $accesses[2] ? 'X' : ' ',
            //         ]);
            //     }
            //     $table2->render();
            // }
        }

        // TODO: Return failure as well
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
