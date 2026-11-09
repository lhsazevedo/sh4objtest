<?php

namespace Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations;

use Lhsazevedo\Sh4ObjTest\Simulator\Types\U32;

/**
 * An operand cache block instruction. Memory has no cache model, so only the
 * instruction and its address are observable.
 */
readonly class CacheBlockOperation extends AbstractOperation {
    public function __construct(
        int $code,
        int $opcode,
        public CacheBlockAction $action,
        public U32 $target,
    ) {
        parent::__construct($code, $opcode);
    }
}
