<?php

namespace Lhsazevedo\Sh4ObjTest\Parser;

use Lhsazevedo\Sh4ObjTest\Parser\Chunks\FileHeader;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\UnitHeader;

class ParsedObject {
    public function __construct(
        public UnitHeader $unit,
        public ?FileHeader $fileHeader = null,
    )
    {}
}
