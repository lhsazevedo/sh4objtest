<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Parser\Chunks;

class Relocation {
    ////// Link/Simulation properties //////
    public ?int $linkedAddress = null;

    public function __construct(
        public readonly int $flags,
        public readonly int $address,
        public readonly int $bitloc,
        public readonly int $fieldLength,
        public readonly int $bcount,
        public readonly int $operator,
        public readonly int $section,
        public readonly int $opcode,
        public readonly int $addendLen,
        public readonly int $relLen,
        // public int $ukn1,
        // public int $ukn2,
        public readonly int $importIndex,
        // public int $ukn3,
        public readonly string $name,
        public readonly int $offset,
    ) {
        // if ($operator != 8) {
        //     throw new \Exception("Unsupported relocation operator $operator", 1);
        // }
    }

    public function __toString(): string
    {
        return "Relocation($this->name)";
    }

    ////// Link/Simulation methods //////

    public function rellocate(int $address): void
    {
        $this->linkedAddress = $this->address + $address;
    }
}