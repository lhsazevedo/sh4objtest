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
    name: 'test',
    description: 'Run tests against a SH4 object file',
)]
class TestCommand extends Command
{
    public function  configure(): void
    {
        $this->addArgument('test', InputArgument::REQUIRED, 'The test case to run')
            ->addArgument('object', InputArgument::REQUIRED, 'The object file to test against')
            ->addOption('disasm', 'd', InputOption::VALUE_NONE, 'Print asm instructions during test execution');
    }

    public function execute (InputInterface $input, OutputInterface $output): int
    {
        $testFile = $input->getArgument('test');

        // $testCase->_inject($input, $output);
        // $testCase->parseObject();

        if ($input->getOption('disasm')) {
            // $testCase->enableDisasm();
        }

        // TODO: Setup and teardown.

        // echo "# $testFile against $objectFile\n";

        $runner = new Runner(
            output: $output,
            shouldOutputDisasm: $input->getOption('disasm'),
        );

        $runner->runFile($testFile, $input->getArgument('object'));

        return Command::SUCCESS;
    }
}
