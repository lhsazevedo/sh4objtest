<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

use Lhsazevedo\Sh4ObjTest\Parser\ParsedObject;

readonly class TestCaseDTO
{
    public function __construct(
        public string $name,

        public string $objectFile,

        public ParsedObject $parsedObject,

        /** @var MemoryInitialization[] */
        public array $initializations,

        /** @var TestRelocation[] */
        public array $testRelocations,

        /** @var Expectations\AbstractExpectation[] */
        public array $expectations,

        public Entry $entry,

        public string $linkedCode,

        public bool $shouldRandomizeMemory,

        public bool $shouldStopWhenFulfilled,
    )
    { }
}
