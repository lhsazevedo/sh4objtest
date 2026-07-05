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

        /** @var array<string, \Closure> */
        public array $defaultCallbacks,

        /** @var array<string, \Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions\CallingConvention> */
        public array $defaultConventions,

        // public Entry $entry,

        public bool $shouldRandomizeMemory,

        public bool $shouldStopWhenFulfilled,
    )
    { }
}
