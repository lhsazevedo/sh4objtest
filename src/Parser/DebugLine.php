<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Parser;

class DebugLine
{
    /**
     * @param int[] $callSites Addresses of the function calls emitted on this
     *                         source line; there are exactly $callCount of them.
     */
    public function __construct(
        public readonly int $fileNumber,
        public readonly int $lineNumber,
        public readonly int $sectionNumber,
        public readonly int $fromAddress,
        public readonly int $toAddress,
        public readonly int $callCount,
        public readonly array $callSites = [],
    ) {}
}
