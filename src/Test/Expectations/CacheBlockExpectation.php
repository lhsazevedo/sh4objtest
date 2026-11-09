<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test\Expectations;

use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations\CacheBlockAction;

class CacheBlockExpectation extends AbstractExpectation
{
    public function __construct(
        public CacheBlockAction $action,
        public int $address,
    ) {}
}
