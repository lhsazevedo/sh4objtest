<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test\Expectations;

class ReturnExpectation extends AbstractExpectation
{
    public function __construct(
        public readonly int|float $value,
    ) {}
}
