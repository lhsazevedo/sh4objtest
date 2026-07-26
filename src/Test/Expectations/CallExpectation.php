<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test\Expectations;

use Lhsazevedo\Sh4ObjTest\Simulator\Arguments\LocalArgument;
use Lhsazevedo\Sh4ObjTest\Simulator\Arguments\WildcardArgument;
use Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions\CallingConvention;

class CallExpectation extends AbstractExpectation
{
    /** @var array<int|float|string|WildcardArgument|LocalArgument> */
    public array $parameters = [];

    public int|float|null $return = null;

    public ?\Closure $callback = null;

    public ?CallingConvention $convention = null;

    public ?int $variadicFixed = null;

    public function __construct(
        public ?string $name,
        public int $address,
    ) {}

    public function with(int|float|string|WildcardArgument|LocalArgument ...$parameters): self
    {
        $this->parameters = $parameters;
        return $this;
    }

    public function andReturn(int|float $value): self
    {
        $this->return = $value;
        return $this;
    }

    public function do(\Closure $callback): self
    {
        $this->callback = $callback;
        return $this;
    }

    public function using(CallingConvention $convention): self
    {
        $this->convention = $convention;
        return $this;
    }

    public function variadic(int $fixed): self
    {
        $this->variadicFixed = $fixed;
        return $this;
    }
}
