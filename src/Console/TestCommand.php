<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Console;

use Lhsazevedo\Sh4ObjTest\Test\ConsoleEventListener;
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
            ->addOption('disasm', 'd', InputOption::VALUE_NONE, 'Print asm instructions during test execution')
            ->addOption('fail-fast', null, InputOption::VALUE_NONE, 'Stop at the first failing test instead of running the rest of the file');
    }

    public function execute (InputInterface $input, OutputInterface $output): int
    {
        $testFile = $input->getArgument('test');

        $runner = new Runner(
            events: new ConsoleEventListener($output),
            shouldOutputDisasm: $input->getOption('disasm'),
            failFast: (bool) $input->getOption('fail-fast'),
        );

        $result = $runner->runFile($testFile, $input->getArgument('object'));

        return $result->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
    }
}
