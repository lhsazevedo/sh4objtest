<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

use Lhsazevedo\Sh4ObjTest\Parser\Chunks\ExternalRelocation;
use Lhsazevedo\Sh4ObjTest\Simulator\SymbolTable;

/**
 * A linked object ready to simulate: the patched memory image plus the symbol
 * table, entry points, and any relocations left unresolved by the test case.
 */
readonly class LinkedProgram
{
    public function __construct(
        /** Patched section bytes, to be loaded at address 0. */
        public string $image,

        public SymbolTable $symbols,

        /** @var ExternalRelocation[] */
        public array $unresolvedRelocations,

        /** @var array<string, int> Exported symbol name => entry address. */
        private array $entryPoints,
    ) {}

    public function resolveEntryAddress(string $name): ?int
    {
        return $this->entryPoints[$name] ?? null;
    }
}
