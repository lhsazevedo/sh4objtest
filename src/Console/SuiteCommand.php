<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Console;

use Lhsazevedo\Sh4ObjTest\Test\Runner;
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
            ->addOption('coverage-json', null, InputOption::VALUE_REQUIRED, 'Write the full coverage report (including variables) to a JSON file')
            ->addOption('coverage-full', null, InputOption::VALUE_NONE, 'Show all uncovered ranges and unaccessed variables instead of a trimmed summary')
            ->addArgument('testcase', InputArgument::OPTIONAL, 'The test case to run');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $suiteFile = getcwd() . '/tests.php';

        if ($suiteFile = $input->getOption('suite')) {
            $suiteFile = realpath($suiteFile);
        }

        $coverageJsonPath = $input->getOption('coverage-json');

        $runner = new Runner(
            output: $output,
            shouldOutputDisasm: $input->getOption('disasm'),
            // A JSON report implies coverage tracking.
            shouldTrackCoverage: $input->getOption('coverage') || $coverageJsonPath !== null,
            coverageJsonPath: $coverageJsonPath,
            coverageFull: $input->getOption('coverage-full'),
        );

        return $runner->runSuite($suiteFile, $input->getArgument('testcase'))
            ? Command::SUCCESS
            : Command::FAILURE;
    }
}
