<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test\Expectations;

class StringWriteExpectation extends AbstractExpectation
{
    public function __construct(
        public int $address,
        public string $value,
    ) {}
}
