<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Parser\Chunks;

/**
 * A relocation whose target is an imported (external) symbol — one defined in
 * another object and resolved at link time.
 *
 * SYSROF encodes the relocation value as a small postfix expression (see
 * ObjectParser); once evaluated it reduces to an import symbol plus a signed
 * addend. Targets within this same object are emitted as InternalRelocation.
 */
class ExternalRelocation {
    ////// Link/Simulation properties //////
    public ?int $linkedAddress = null;

    public function __construct(
        /** Offset of the patched field within its section. */
        public readonly int $address,

        /** Imported symbol name the field resolves to. */
        public readonly ?string $name,

        /** Signed addend applied on top of the symbol address. */
        public readonly int $addend,

        /** Raw attribute byte (bit 0 = expression carries the addend). */
        public readonly int $attributes = 0,

        /** Width of the patched field in bytes (4 for .DATA.L, 2 for .DATA.W). */
        public readonly int $fieldWidth = 4,
    ) {}

    public function __toString(): string
    {
        return "ExternalRelocation($this->name)";
    }

    ////// Link/Simulation methods //////

    public function rellocate(int $address): void
    {
        $this->linkedAddress = $this->address + $address;
    }
}
