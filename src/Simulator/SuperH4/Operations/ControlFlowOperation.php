<?php declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations;

use Lhsazevedo\Sh4ObjTest\Simulator\Types\U32;

readonly class ControlFlowOperation extends AbstractOperation
{
    public function __construct(
        int $code,
        int $opcode,
        public U32 $target,
        public bool $taken = true,
    ) {
        parent::__construct($code, $opcode);
    }

    public function isCall(): bool
    {
        // BSR, JSR
        return in_array($this->opcode, [0xB000, 0x400B]);
    }

    public function isConditional(): bool
    {
        // BT, BF, BT/S, BF/S
        return in_array($this->opcode, [0x8900, 0x8B00, 0x8D00, 0x8F00]);
    }

    public function hasDelaySlot(): bool
    {
        // BT, BF
        return !in_array($this->opcode, [0x8900, 0x8B00]);
    }
}
