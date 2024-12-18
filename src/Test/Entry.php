<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

class Entry {
    public function __construct(
        public ?string $symbol = null,

        /** @var int[]|float[] */
        public array $parameters = [],

        // TODO: functions can return pointers
        public ?int $return = null,

        public ?float $floatReturn = null,
    ) {}
}
