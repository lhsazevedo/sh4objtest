<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Parser;

class LocalRelocationLong
{
    public function __construct(
        public readonly int $sectionIndex,
        public readonly int $address,
        public readonly int $target,
    ) {}
}