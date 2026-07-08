<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

use Symfony\Component\Console\Output\OutputInterface;

class ConsoleEventListener extends AbstractEventListener
{
    public function __construct(private OutputInterface $output)
    {
    }

    protected function write(string $line): void
    {
        $this->output->writeln($line);
    }
}
