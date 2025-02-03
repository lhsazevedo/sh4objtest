<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test\Expectations;

class CallCommand extends AbstractExpectation
{
    /** @var array<int|float> */
    public array $arguments = [];

    public function __construct(
        public string $symbol,
    ) {}

    public function with(int|float ...$arguments): self
    {
        $this->arguments = $arguments;
        return $this;
    }
}
