<?php

namespace Lhsazevedo\Sh4ObjTest\Parser;

readonly class ImportSymbol
{
    public function __construct(
        public string $name,
        public int $type,
    ) {}
}
