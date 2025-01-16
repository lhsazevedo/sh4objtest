<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test\Expectations;

class ReadExpectation extends AbstractExpectation
{
    public function __construct(
        public int $address,
        public int $value,
        public int $size,
    ) {
        /* TODO: Move this to value object? */
        if ($value < -(2 ** $size - 1)) {
            throw new \RuntimeException("Value $value is too small for $size bits");
        } elseif ($value >= 2 ** $size) {
            throw new \RuntimeException("Value $value is too big for $size bits");
        }

        $this->value &= (2**$size) - 1;
    }
}
