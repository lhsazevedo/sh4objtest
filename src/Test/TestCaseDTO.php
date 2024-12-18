<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

use Lhsazevedo\Sh4ObjTest\ParsedObject;

readonly class TestCaseDTO
{
    public function __construct(
        public string $name,

        public string $objectFile,

        public ParsedObject $parsedObject,

        /** @var Lhsazevedo\Sh4ObjTest\Test\MemoryInitialization[] */
        public array $initializations,

        /** @var Lhsazevedo\Sh4ObjTest\Test\TestRelocation[] */
        public array $testRelocations,

        /** @var Lhsazevedo\Sh4ObjTest\Test\Expectations\AbstractExpectation[] */
        public array $expectations,

        public Entry $entry,

        public string $linkedCode,

        public bool $shouldRandomizeMemory,

        public bool $shouldStopWhenFulfilled,
    )
    { }
}
