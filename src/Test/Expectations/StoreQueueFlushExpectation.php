<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test\Expectations;

class StoreQueueFlushExpectation extends AbstractExpectation
{
    public function __construct(
        public int $address,
    ) {}
}
