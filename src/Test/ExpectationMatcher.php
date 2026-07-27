<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

use Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions\DefaultCallingConvention;
use Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions\VariadicCallingConvention;
use Lhsazevedo\Sh4ObjTest\Simulator\Exceptions\ExpectationException;
use Lhsazevedo\Sh4ObjTest\Simulator\Simulator;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations\BranchOperation;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations\ReadOperation;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations\WriteOperation;
use Lhsazevedo\Sh4ObjTest\Simulator\SymbolTable;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U32;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\AbstractExpectation;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\CallExpectation;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\ReadExpectation;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\StringWriteExpectation;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\WriteExpectation;

/**
 * Consumes simulator write/read/call events against the queue of pending
 * expectations. Owns the queue itself, since Run's loop also needs to
 * peek/shift call-command and return expectations from the same sequence.
 */
class ExpectationMatcher
{
    /** @var AbstractExpectation[] */
    private array $pending;

    /**
     * @param AbstractExpectation[] $expectations
     * @param TestRelocation[] $testRelocations
     * @param array<string, \Closure> $defaultCallbacks
     * @param array<string, \Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions\CallingConvention> $defaultConventions
     * @param \Lhsazevedo\Sh4ObjTest\Parser\Chunks\ExternalRelocation[] $unresolvedRelocations
     * @param \Closure(string): void $onFulfilled
     * @param \Closure(string): void $onInfo
     */
    public function __construct(
        array $expectations,
        private SymbolTable $symbols,
        private array $testRelocations,
        private array $defaultCallbacks,
        private array $defaultConventions,
        private array $unresolvedRelocations,
        private ArgumentVerifier $argumentVerifier,
        private \Closure $onFulfilled,
        private \Closure $onInfo,
    )
    {
        $this->pending = $expectations;
    }

    public function peek(): AbstractExpectation|false
    {
        return reset($this->pending);
    }

    public function shift(): ?AbstractExpectation
    {
        return array_shift($this->pending);
    }

    public function isEmpty(): bool
    {
        return empty($this->pending);
    }

    /** @return AbstractExpectation[] */
    public function remaining(): array
    {
        return $this->pending;
    }

    public function matchWrite(Simulator $simulator, WriteOperation $instruction): void
    {
        $address = $instruction->target->value;
        $value = $instruction->value;

        $expectation = $this->peek();
        $readableAddress = '0x' . dechex($address);
        $readableValue = $value->readable();

        // TODO: I really don't like how we need to keep checking for the expectation type here.

        // Stack write
        if ($address >= $simulator->getRegister(15)->value) {
            // Unexpected stack writes are allowed
            if (!($expectation instanceof WriteExpectation
                    || $expectation instanceof StringWriteExpectation)
                || $expectation->address !== $address
            ) {
                ($this->onInfo)("Allowed stack write of $readableValue to $readableAddress");
                return;
            }
        } else if (!($expectation instanceof WriteExpectation || $expectation instanceof StringWriteExpectation)) {
            throw new ExpectationException("Unexpected write of " . $readableValue . " to " . $readableAddress . "\n");
        }

        if ($symbol = $this->getSymbolNameAt($address)) {
            $readableAddress = "$symbol($readableAddress)";
        }

        $readableExpectedAddress = '0x' . dechex($expectation->address);
        if ($symbol = $this->getSymbolNameAt($expectation->address)) {
            $readableExpectedAddress = "$symbol($readableExpectedAddress)";
        }

        // Handle char* writes
        if (is_string($expectation->value)) {
            if (!($expectation instanceof StringWriteExpectation)) {
                throw new ExpectationException("Unexpected char* write of $readableValue to $readableAddress, expecting int write of $readableExpectedAddress");
            }

            if ($value::BIT_COUNT !== 32) {
                throw new ExpectationException("Unexpected non 32bit char* write of $readableValue to $readableAddress");
            }

            $actual = $simulator->getMemory()->readString($value->value);
            $readableValue = $actual . ' (' . bin2hex($actual) . ')';
            $readableExpectedValue = $expectation->value . ' (' . bin2hex($expectation->value) . ')';

            if ($expectation->address !== $address) {
                throw new ExpectationException("Unexpected write address $readableAddress. Expecting writring of $readableExpectedValue to $readableExpectedAddress");
            }

            if ($actual !== $expectation->value) {
                throw new ExpectationException("Unexpected char* write value $readableValue to $readableAddress, expecting $readableExpectedValue");
            }

            ($this->onFulfilled)("Wrote string $readableValue to $readableAddress");
        }
        // Hanlde int writes
        else {
            if (!($expectation instanceof WriteExpectation)) {
                throw new ExpectationException("Unexpected int write of $readableValue to $readableAddress, expecting char* write of $readableExpectedAddress");
            }

            if ($value::BIT_COUNT !== $expectation->size) {
                throw new ExpectationException("Unexpected " . $value::BIT_COUNT . " bit write of $readableValue to $readableAddress, expecting $expectation->size bit write");
            }

            $readableExpectedValue = $expectation->value . '(0x' . dechex($expectation->value) . ')';
            if ($expectation->address !== $address) {
                throw new ExpectationException("Unexpected write address $readableAddress. Expecting writring of $readableExpectedValue to $readableExpectedAddress");
            }

            if ($value->lessThan(0)) {
                throw new ExpectationException("Unexpected negative write value $readableValue to $readableAddress");
            }

            if (!$value->equals($expectation->value)) {
                throw new ExpectationException("Unexpected write value $readableValue to $readableAddress, expecting value $readableExpectedValue");
            }

            ($this->onFulfilled)("Wrote $readableValue to $readableAddress");
        }

        $this->shift();
    }

    public function matchRead(Simulator $simulator, ReadOperation $instruction): void
    {
        foreach ($this->unresolvedRelocations as $relocation) {
            if ($relocation->linkedAddress !== $instruction->source->value) {
                continue;
            }

            throw new \Exception(
                "Trying to read from unresolved relocation $relocation->name",
                1
            );
        }

        $displacedAddr = $instruction->source->value;

        $readableAddress = '0x' . dechex($displacedAddr);
        if ($symbol = $this->getSymbolNameAt($displacedAddr)) {
            $readableAddress = "$symbol($readableAddress)";
        }

        $expectation = $this->peek();

        $value = $instruction->value;
        $readableValue = $value . ' (0x' . dechex($value->value) . ')';

        $size = $instruction->value::BIT_COUNT;

        // Handle read expectations
        if ($expectation instanceof ReadExpectation && $expectation->address === $displacedAddr) {
            $readableExpected = $expectation->value . ' (0x' . dechex($expectation->value) . ')';

            if ($size !== $expectation->size) {
                throw new ExpectationException("Unexpected read size $size from $readableAddress. Expecting size $expectation->size");
            }

            if (!$value->equals($expectation->value)) {
                throw new ExpectationException("Unexpected read of $readableValue from $readableAddress. Expecting value $readableExpected");
            }

            ($this->onFulfilled)("Read $readableExpected from $readableAddress");
            $this->shift();
        }
    }

    /**
     * Handles a delayed branch. Returns whether the run loop should stop
     * (the program jumped away rather than performing an expected call).
     */
    public function matchBranch(Simulator $simulator, BranchOperation $instruction): bool
    {
        // Branch to symbols are calls and must be expected
        if ($this->symbols->getSymbolAtAddress($instruction->target)) {
            $this->assertCall($simulator, $instruction->target->value);

            if ($instruction->isCall()) {
                $simulator->setPc($simulator->getPr());
                $simulator->cancelDelayedBranch();
                return false;
            }

            // Program jumped to another symbol.
            ($this->onInfo)("Program jumped to symbol at " . $instruction->target->hex());
            return true;
        }

        // Branch to non-symbol are checked only
        // if the address matches the expectation
        $expectation = $this->peek();
        if ($expectation instanceof CallExpectation && $instruction->target->equals($expectation->address)) {
            $this->assertCall($simulator, $instruction->target->value);

            if ($instruction->isCall()) {
                $simulator->setPc($simulator->getPr());
                $simulator->cancelDelayedBranch();
                return false;
            }

            // Stop execution on dynamic tail calls
            ($this->onInfo)("Program jumped to address " . $instruction->target->hex());
            return true;
        }

        return false;
    }

    private function assertCall(Simulator $simulator, int $target): void
    {
        $name = null;
        $readableName = "<NO_SYMBOL>";

        if ($export = $this->symbols->getSymbolAtAddress(U32::of($target))) {
            $name = $export->name;
            $readableName = "$name (" . U32::of($target)->hex() . ")";
        } elseif ($resolution = $this->getResolutionAt($target)) {
            $name = $resolution->name;
            $readableName = "$name (" . U32::of($target)->hex() . ")";
        }

        /** @var AbstractExpectation */
        $expectation = $this->shift();

        if (!($expectation instanceof CallExpectation)) {
            throw new ExpectationException("Unexpected function call to $readableName at " . dechex($simulator->getPc()));
        }

        if ($name !== $expectation->name) {
            throw new ExpectationException("Unexpected call to $readableName at " . dechex($simulator->getPc()) . ", expecting $expectation->name");
        }

        if ($expectation->parameters) {
            $convention = $expectation->convention
                ?? ($name !== null ? ($this->defaultConventions[$name] ?? null) : null)
                ?? new DefaultCallingConvention();

            if ($expectation->variadicFixed !== null) {
                if (!$convention instanceof VariadicCallingConvention) {
                    throw new \Exception(get_class($convention) . " does not support variadic arguments, but variadic() was set on the expectation for $readableName");
                }
                $convention->variadic($expectation->variadicFixed);
            }

            foreach ($expectation->parameters as $expected) {
                $this->argumentVerifier->verify($simulator, $convention, $expected, $readableName);
            }
        }

        // TODO: Temporary hack to modify write during runtime
        $callback = $expectation->callback
            ?? ($name !== null ? ($this->defaultCallbacks[$name] ?? null) : null);

        if ($callback) {
            $callback = \Closure::bind($callback, $simulator, $simulator);
            $callback($expectation->parameters);
        }

        if ($expectation->return !== null) {
            match (gettype($expectation->return)) {
                "integer" => $simulator->setRegister(0, U32::of($expectation->return & 0xffffffff)),
                "double" => $simulator->setFloatRegister(0, $expectation->return),
            };
        }

        ($this->onFulfilled)("Called " . $readableName . '(0x'. dechex($target) . ")");
    }

    private function getResolutionAt(int $address): ?TestRelocation
    {
        foreach ($this->testRelocations as $relocation) {
            if ($relocation->address === $address) {
                return $relocation;
            }
        }

        return null;
    }

    private function getSymbolNameAt(int $address): ?string
    {
        if ($relocation = $this->getResolutionAt($address)) {
            return $relocation->name;
        }

        if ($export = $this->symbols->getSymbolAtAddress(U32::of($address))) {
            return $export->name;
        }

        return null;
    }
}
