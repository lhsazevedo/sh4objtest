<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions;

interface VariadicCallingConvention
{
    /**
     * Marks $fixed as the number of leading fixed arguments; arguments at or
     * past that position must be dispensed from the stack, per the SHC ABI.
     */
    public function variadic(int $fixed): static;
}
