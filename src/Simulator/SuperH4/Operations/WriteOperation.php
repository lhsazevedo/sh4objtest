<?php

namespace Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations;

use Lhsazevedo\Sh4ObjTest\Simulator\Types\U32;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\UInt;

readonly class WriteOperation extends AbstractOperation {
    public function __construct(
        int $code, 
        int $opcode,
        public U32 $target,
        public UInt $value,
    ) {
        parent::__construct($code, $opcode);
    }
}
