<?php
declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Simulator;

use Lhsazevedo\Sh4ObjTest\Simulator\Types\U32;

readonly class Symbol
{
    public function __construct(
        public string $name,
        // public string $type,
        public U32 $address,
    ) {}
}
