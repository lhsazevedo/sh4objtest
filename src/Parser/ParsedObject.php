<?php

namespace Lhsazevedo\Sh4ObjTest\Parser;

use Lhsazevedo\Sh4ObjTest\Parser\Chunks\UnitHeader;

class ParsedObject {
    /**
     * @param array<int,int> $skippedChunkTypes Count of skipped (unhandled)
     *                                           chunks, keyed by raw chunk type.
     */
    public function __construct(
        public UnitHeader $unit,
        public array $skippedChunkTypes = [],
    )
    {}
}
