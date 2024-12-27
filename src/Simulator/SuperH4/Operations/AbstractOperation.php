<?php declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations;

readonly abstract class AbstractOperation
{
    public function __construct(
        public int $code,
        public int $opcode,
    ) {
    }
}
