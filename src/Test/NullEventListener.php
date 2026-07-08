<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

/** Discards all events; used for --format=json, where only the final document is printed. */
class NullEventListener extends AbstractEventListener
{
    protected function write(string $line): void
    {
    }
}
