<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

readonly class MemoryInitialization
{
    public function __construct(
        public int $size,
        public int $address,
        public int $value
    )
    {}
}
