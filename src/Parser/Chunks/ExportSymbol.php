<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Parser\Chunks;

class ExportSymbol
{
    public int $linkedAddress;

    public function __construct(
        public string $name,
        public readonly int $section,
        public readonly int $type,
        public readonly int $offset,
    ) {}

    public function rellocate(int $address): void
    {
        $this->linkedAddress = $this->offset + $address;
    }
}
