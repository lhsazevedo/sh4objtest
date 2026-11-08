<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test\Expectations;

class CallCommand extends AbstractExpectation
{
    /** @var array<int|float> */
    public array $arguments = [];

    public ?int $variadicFixed = null;

    public function __construct(
        public string $symbol,
    ) {}

    public function with(int|float ...$arguments): self
    {
        $this->arguments = $arguments;
        return $this;
    }

    /** The first $fixed arguments in registers, the rest on the stack */
    public function variadic(int $fixed): self
    {
        $this->variadicFixed = $fixed;
        return $this;
    }
}
