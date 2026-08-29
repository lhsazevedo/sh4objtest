<?php

namespace Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations;

use Lhsazevedo\Sh4ObjTest\Simulator\Types\U32;

/**
 * A PREF @Rn whose target fell in the store-queue range: it triggers a
 * hardware burst of the queue staged by prior stores to that range. The
 * burst's destination and contents aren't modeled (nothing in a test can
 * read them back) -- only the trigger itself, at $target, is observable.
 */
readonly class StoreQueueFlushOperation extends AbstractOperation {
    public function __construct(
        int $code,
        int $opcode,
        public U32 $target,
    ) {
        parent::__construct($code, $opcode);
    }
}
