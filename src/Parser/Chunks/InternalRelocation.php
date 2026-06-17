<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Parser\Chunks;

/**
 * A relocation whose target is a section defined in this same object — an
 * "internal" (section-relative) relocation.
 *
 * The addend may be stored explicitly in the relocation record ($addend set,
 * ELF RELA style) or implicitly at the patched site within the section data
 * ($addend === null, ELF REL style).
 */
class InternalRelocation {
    ////// Link/Simulation properties //////
    public ?int $linkedAddress = null;

    public function __construct(
        /** Index of the target section within the unit. */
        public readonly int $sectionIndex,

        /** Offset of the patched field within its own section. */
        public readonly int $address,

        /** Explicit addend, or null when carried in-place at the patched site. */
        public readonly ?int $addend = null,
    ) {}

    ////// Link/Simulation methods //////

    public function rellocate(int $address): void
    {
        $this->linkedAddress = $this->address + $address;
    }
}
