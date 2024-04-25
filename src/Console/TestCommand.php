<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Console;

use Lhsazevedo\Sh4ObjTest\Simulator\Exceptions\ExpectationException;
use ReflectionClass;
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
            ->addArgument('object', InputArgument::OPTIONAL, 'The object file to test against')
            ->addOption('disasm', 'd', InputOption::VALUE_NONE, 'Print asm instructions during test execution');
    }

    public function execute (InputInterface $input, OutputInterface $output): int
    {
        $testFile = $input->getArgument('test');
        $test = require $testFile;
        $test->_inject($input, $output);

        if ($objectFile = $input->getArgument('object')) {
            $test->setObjectFile($objectFile);
        }

        $test->parseObject();

        if ($input->getOption('disasm')) {
            $test->enableDisasm();
        }

        $reflectionClass = new ReflectionClass($test);

        // TODO: Setup and teardown.

        echo "# $testFile against $objectFile\n";

        try {
            foreach ($reflectionClass->getMethods() as $reflectionMethod) {
                if ($reflectionMethod->isPublic() && str_starts_with($reflectionMethod->name, 'test')) {
                    echo $reflectionMethod->name . "...\n";
                    call_user_func([$test, $reflectionMethod->name]);
                }
            }
        } catch (ExpectationException $e) {
            $output->writeln("\n<bg=red> FAILED EXPECTATION </> <fg=red>{$e->getMessage()}</>\n");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
