<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Console;

use Lhsazevedo\Sh4ObjTest\Test\CompactEventListener;
use Lhsazevedo\Sh4ObjTest\Test\ConsoleEventListener;
use Lhsazevedo\Sh4ObjTest\Test\Controller;
use Lhsazevedo\Sh4ObjTest\Test\CoverageReporter;
use Lhsazevedo\Sh4ObjTest\Test\NullEventListener;
use Lhsazevedo\Sh4ObjTest\Test\Runner;
use Lhsazevedo\Sh4ObjTest\Test\SuiteResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'suite',
    description: 'Run a test suite',
)]
class SuiteCommand extends Command
{
    public function  configure(): void
    {
        $this->addOption('suite', 's', InputOption::VALUE_REQUIRED, 'The suite to run')
            ->addOption('disasm', 'd', InputOption::VALUE_NONE, 'Print asm instructions during test execution')
            ->addOption('coverage', 'c', InputOption::VALUE_NONE, 'Print coverage information')
            ->addOption('coverage-full', null, InputOption::VALUE_NONE, 'Show all uncovered ranges and unaccessed variables instead of a trimmed summary')
            ->addOption('fail-fast', null, InputOption::VALUE_NONE, 'Stop at the first failing test instead of running the rest of the suite')
            ->addOption('parallel', 'p', InputOption::VALUE_NONE, 'Run test files across multiple worker processes')
            ->addOption('workers', null, InputOption::VALUE_REQUIRED, 'Number of parallel worker processes (default: number of CPU cores). Only meaningful with --parallel')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: "pretty" or "json"', 'pretty')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write --format=json output to this file instead of stdout')
            ->addArgument('testcase', InputArgument::OPTIONAL, 'The test case to run');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $suiteFile = getcwd() . '/tests.php';

        if ($suiteFile = $input->getOption('suite')) {
            $suiteFile = realpath($suiteFile);
        }

        $suiteDir = dirname($suiteFile);
        $sourcePaths = (require $suiteFile)['sourcePaths'] ?? [];

        $format = $input->getOption('format');
        if (!in_array($format, ['pretty', 'json'], true)) {
            $output->writeln("<error>Invalid --format \"{$format}\", expected \"pretty\" or \"json\".</error>");
            return Command::INVALID;
        }

        $parallel = (bool) $input->getOption('parallel');
        $disasm = (bool) $input->getOption('disasm');

        if ($parallel && $disasm) {
            $output->writeln('<error>--parallel does not support --disasm.</error>');
            return Command::INVALID;
        }

        $shouldTrackCoverage = (bool) $input->getOption('coverage');
        $coverageFull = (bool) $input->getOption('coverage-full');
        $failFast = (bool) $input->getOption('fail-fast');
        $testCaseFilter = $input->getArgument('testcase');

        // json output is a single document printed at the end; live per-test
        // console lines would otherwise interleave with (or precede) it.
        // Otherwise, -v prints every test as it runs; by default only
        // failures are shown, plus a progress line and a final summary.
        $events = match (true) {
            $format === 'json' => new NullEventListener(),
            $output->isVerbose() || $disasm => new ConsoleEventListener($output),
            default => new CompactEventListener($output),
        };

        if ($parallel) {
            $workersOption = $input->getOption('workers');
            $workerCount = $workersOption !== null ? (int) $workersOption : Controller::detectCoreCount();

            $binPath = self::resolveBinPath((string) ($_SERVER['argv'][0] ?? ''));
            if ($binPath === false) {
                $output->writeln('<error>Could not resolve the sh4objtest binary path for spawning workers.</error>');
                return Command::FAILURE;
            }

            $workItems = Runner::collectWorkItems($suiteFile, $testCaseFilter);
            $controller = new Controller($events, $binPath, $workerCount, $shouldTrackCoverage, $failFast);
            $result = $controller->run($workItems);
        } else {
            $runner = new Runner(
                events: $events,
                shouldOutputDisasm: $disasm,
                failFast: $failFast,
            );

            $result = $runner->runSuite($suiteFile, $testCaseFilter);
        }

        $this->printResult($output, $result, $format, $input->getOption('output'), $shouldTrackCoverage, $coverageFull, $sourcePaths, $suiteDir);

        return $result->success ? Command::SUCCESS : Command::FAILURE;
    }

    private static function resolveBinPath(string $argv0): string|false
    {
        // Inside a PHAR (php foo.phar) or a phpmicro self-executable — the two
        // distributed forms — this is the absolute archive/executable path
        // however it was invoked. Empty only for the raw entry script, where
        // argv[0] is a real relative/CWD path realpath() can resolve.
        $running = \Phar::running(false);
        if ($running !== '') {
            return $running;
        }

        return realpath($argv0);
    }

    /** @param array<string,string> $sourcePaths */
    private function printResult(
        OutputInterface $output,
        SuiteResult $result,
        string $format,
        ?string $outputPath,
        bool $shouldTrackCoverage,
        bool $coverageFull,
        array $sourcePaths,
        string $suiteDir,
    ): void
    {
        $coverageData = $shouldTrackCoverage ? CoverageReporter::collect($result->objectResults, $sourcePaths, $suiteDir) : null;

        if ($format === 'json') {
            $document = [
                'success' => $result->success,
                'files' => $result->files,
                'coverage' => $coverageData,
            ];

            $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

            if ($outputPath !== null) {
                file_put_contents($outputPath, $json);
                $output->writeln("<info>Wrote results to {$outputPath}</info>");
            } else {
                $output->write($json);
            }

            return;
        }

        if ($coverageData !== null) {
            CoverageReporter::render($output, $coverageData, $coverageFull);
        }
    }
}
