<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

use Lhsazevedo\Sh4ObjTest\ObjectParser;
use Lhsazevedo\Sh4ObjTest\Parser\ParsedObject;
use Lhsazevedo\Sh4ObjTest\Simulator\Exceptions\ExpectationException;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\CallCommand;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\ReturnExpectation;
use Lhsazevedo\Sh4ObjTest\TestCase;

class Runner
{
    /** @var array<string, array{0: ParsedObject, 1: string}> keyed by object file path */
    private static array $linkedObjectCache = [];

    public function __construct(
        private EventListener $events,
        private bool $shouldOutputDisasm = false,
        private bool $failFast = false,
    )
    {}

    public function runFile(string $testFile, string $objectFile): FileResult
    {
        $testCase = require $testFile;
        $reflectedBaseTestCase = new \ReflectionClass(TestCase::class);
        $reflectedTestCase = new \ReflectionClass($testCase);

        // TODO: Check if it is really necessary to
        // pass the parsed object to the test case.
        // A suite's groups fan multiple test files out against the same
        // objects, so cache the parsed+linked object across test files
        // instead of redoing it per test file (safe: rellocate() only
        // happens here, and nothing downstream mutates the parsed object).
        if (!isset(self::$linkedObjectCache[$objectFile])) {
            $parsedObject = ObjectParser::parse($objectFile);
            $linkedCode = self::linkObject($parsedObject);
            self::$linkedObjectCache[$objectFile] = [$parsedObject, $linkedCode];
        }
        [$parsedObject, $linkedCode] = self::$linkedObjectCache[$objectFile];

        $result = new FileResult();

        $this->events->onFileStarted($testFile, $objectFile);

        foreach ($reflectedTestCase->getMethods() as $reflectionMethod) {
            if (!$reflectionMethod->isPublic()) {
                continue;
            }

            if (!str_starts_with($reflectionMethod->name, 'test')) {
                continue;
            }

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
                shouldRandomizeMemory: $reflectedBaseTestCase->getProperty('randomizeMemory')->getValue($currentTestCase),
                shouldStopWhenFulfilled: $reflectedBaseTestCase->getProperty('forceStop')->getValue($currentTestCase),
            );

            $run = new Run(
                $this->events,
                $testCaseDto,
                $this->shouldOutputDisasm,
            );

            try {
                $result->addRun($run->run());
            } catch (ExpectationException $e) {
                $name = Run::humanizeName($reflectionMethod->name);
                $this->events->onTestFailed($name, $e->getMessage());
                $result->addFailure($name, $e->getMessage());

                if ($this->failFast) {
                    break;
                }
            }
        }

        return $result;
    }

    public function runSuite(string $suiteFile, ?string $testCaseFilter): SuiteResult
    {
        $workItems = self::collectWorkItems($suiteFile, $testCaseFilter);

        /** @var array<string, ObjectResult> */
        $objectResults = [];
        $files = [];
        $success = true;

        $this->events->onSuiteStarted(count($workItems));

        foreach ($workItems as $item) {
            try {
                $fileResult = $this->runFile($item['testFile'], $item['objectFile']);
            } catch (\Throwable $e) {
                $this->events->onFileError($item['testFile'], $item['objectFile'], $e->getMessage());
                $this->events->onFileFinished($item['testFile'], $item['objectFile'], false);

                $files[] = [
                    'testFile' => $item['testFile'],
                    'objectFile' => $item['objectFile'],
                    'success' => false,
                    'tests' => [],
                    'error' => $e->getMessage(),
                ];
                $success = false;

                if ($this->failFast) {
                    break;
                }

                continue;
            }

            $objectResults[$item['objectFile']] ??= new ObjectResult($item['objectFile']);
            $objectResults[$item['objectFile']]->mergeCoverage($fileResult->coverage);

            $fileSuccess = $fileResult->isSuccessful();
            $files[] = [
                'testFile' => $item['testFile'],
                'objectFile' => $item['objectFile'],
                'success' => $fileSuccess,
                'tests' => $fileResult->getTests(),
            ];

            $this->events->onFileFinished($item['testFile'], $item['objectFile'], $fileSuccess);

            if (!$fileSuccess) {
                $success = false;

                if ($this->failFast) {
                    break;
                }
            }
        }

        $result = new SuiteResult($success, $objectResults, $files);
        $this->events->onSuiteFinished($result);

        return $result;
    }

    /**
     * Flattens a suite file's groups into the (testFile, objectFile) pairs
     * that need to run — the cartesian product of each group's objects and
     * tests. Shared by sequential runSuite() and the parallel Controller so
     * both dispatch identical work.
     *
     * @return array{testFile: string, objectFile: string}[]
     */
    public static function collectWorkItems(string $suiteFile, ?string $testCaseFilter): array
    {
        $suite = require $suiteFile;
        $suiteDir = dirname($suiteFile);

        $items = [];
        foreach ($suite['groups'] as $group) {
            foreach ($group['objects'] as $object) {
                foreach ($group['tests'] as $test) {
                    $filePath = realpath("$suiteDir/$test");
                    // TODO: Filter files before running tests, and error if no files are found.
                    if ($testCaseFilter && !str_starts_with($filePath, $testCaseFilter)) {
                        continue;
                    }

                    $objectPath = realpath("$suiteDir/$object");
                    $items[] = ['testFile' => $filePath, 'objectFile' => $objectPath];
                }
            }
        }

        return $items;
    }

    public static function linkObject(ParsedObject $object): string {
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
