<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

readonly class TestCaseDTO
{
    public function __construct(
        public string $name,

        public string $objectFile,

        public LinkedProgram $linkedProgram,

        /** @var MemoryInitialization[] */
        public array $initializations,

        /** @var TestRelocation[] */
        public array $testRelocations,

        /** @var Expectations\AbstractExpectation[] */
        public array $expectations,

        // public Entry $entry,

        public bool $shouldRandomizeMemory,

        public bool $shouldStopWhenFulfilled,
    )
    { }
}
