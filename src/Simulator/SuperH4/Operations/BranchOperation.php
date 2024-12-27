<?php

namespace Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations;

use Lhsazevedo\Sh4ObjTest\Simulator\Types\U32;

readonly class BranchOperation extends ControlFlowOperation
{
    public function __construct(
        int $code, 
        int $opcode,
        public U32 $target,
    ) {
        parent::__construct($code, $opcode);
    }

    public function isRelative()
    {
        return in_array($this->opcode, [0xA000, 0xB000]);
    }

    public function isAbsolute()
    {
        return !$this->isRelative();
    }

    public function isCall()
    {
        return in_array($this->opcode, [0xB000, 0x400B]);
    }
}
