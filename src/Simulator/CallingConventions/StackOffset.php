<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions;

readonly class StackOffset
{
    public function __construct(
        public int $offset,
    ) {}
}
