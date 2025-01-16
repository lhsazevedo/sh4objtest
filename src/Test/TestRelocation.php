<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

class TestRelocation
{
    public function __construct(
        public string $name,
        public int $address,
    )
    {}
}
