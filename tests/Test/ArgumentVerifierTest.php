<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Tests\Test;

use Lhsazevedo\Sh4ObjTest\Simulator\Arguments\WildcardArgument;
use Lhsazevedo\Sh4ObjTest\Simulator\BinaryMemory;
use Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions\DefaultCallingConvention;
use Lhsazevedo\Sh4ObjTest\Simulator\Exceptions\ExpectationException;
use Lhsazevedo\Sh4ObjTest\Simulator\Simulator;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U32;
use Lhsazevedo\Sh4ObjTest\Test\ArgumentVerifier;
use PHPUnit\Framework\TestCase;

class ArgumentVerifierTest extends TestCase
{
    private function simulator(): Simulator
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));
        $simulator->setRegister(15, U32::of(512));

        return $simulator;
    }

    public function testIntVerifyPassesOnMatchingRegister(): void
    {
        $simulator = $this->simulator();
        $simulator->setRegister(4, U32::of(42));

        (new ArgumentVerifier())->verify($simulator, new DefaultCallingConvention(), 42, 'fn');

        $this->addToAssertionCount(1);
    }

    public function testIntVerifyThrowsOnMismatchedRegister(): void
    {
        $simulator = $this->simulator();
        $simulator->setRegister(4, U32::of(1));

        $this->expectException(ExpectationException::class);
        (new ArgumentVerifier())->verify($simulator, new DefaultCallingConvention(), 2, 'fn');
    }

    public function testIntVerifyPassesOnStackWhenRegistersExhausted(): void
    {
        $simulator = $this->simulator();
        $convention = new DefaultCallingConvention();
        // Fill R4-R7 so the 5th int argument spills to the stack.
        foreach ([1, 2, 3, 4] as $i => $value) {
            $simulator->setRegister(4 + $i, U32::of($value));
        }
        $simulator->getMemory()->writeUInt32(512, U32::of(5));

        $verifier = new ArgumentVerifier();
        $verifier->verify($simulator, $convention, 1, 'fn');
        $verifier->verify($simulator, $convention, 2, 'fn');
        $verifier->verify($simulator, $convention, 3, 'fn');
        $verifier->verify($simulator, $convention, 4, 'fn');
        $verifier->verify($simulator, $convention, 5, 'fn');

        $this->addToAssertionCount(1);
    }

    public function testFloatVerifyPassesOnMatchingRegister(): void
    {
        $simulator = $this->simulator();
        $simulator->setFloatRegister(4, 1.5);

        (new ArgumentVerifier())->verify($simulator, new DefaultCallingConvention(), 1.5, 'fn');

        $this->addToAssertionCount(1);
    }

    public function testFloatVerifyThrowsOnMismatchedRegister(): void
    {
        $simulator = $this->simulator();
        $simulator->setFloatRegister(4, 1.0);

        $this->expectException(ExpectationException::class);
        (new ArgumentVerifier())->verify($simulator, new DefaultCallingConvention(), 2.0, 'fn');
    }

    public function testStringVerifyPassesOnMatchingRegister(): void
    {
        $simulator = $this->simulator();
        $simulator->getMemory()->writeBytes(100, "hello\0");
        $simulator->setRegister(4, U32::of(100));

        (new ArgumentVerifier())->verify($simulator, new DefaultCallingConvention(), 'hello', 'fn');

        $this->addToAssertionCount(1);
    }

    public function testStringVerifyThrowsOnMismatchedRegister(): void
    {
        $simulator = $this->simulator();
        $simulator->getMemory()->writeBytes(100, "hello\0");
        $simulator->setRegister(4, U32::of(100));

        $this->expectException(ExpectationException::class);
        (new ArgumentVerifier())->verify($simulator, new DefaultCallingConvention(), 'bye', 'fn');
    }

    public function testStringVerifyPassesOnStack(): void
    {
        $simulator = $this->simulator();
        $simulator->getMemory()->writeBytes(100, "hello\0");
        $convention = (new DefaultCallingConvention())->variadic(0);
        $simulator->getMemory()->writeUInt32(512, U32::of(100));

        (new ArgumentVerifier())->verify($simulator, $convention, 'hello', 'fn');

        $this->addToAssertionCount(1);
    }

    public function testWildcardConsumesArgumentSlotWithoutVerifying(): void
    {
        $simulator = $this->simulator();
        $convention = new DefaultCallingConvention();

        (new ArgumentVerifier())->verify($simulator, $convention, new WildcardArgument(), 'fn');

        // The wildcard must have consumed R4, so the next int argument lands in R5.
        $simulator->setRegister(5, U32::of(7));
        (new ArgumentVerifier())->verify($simulator, $convention, 7, 'fn');

        $this->addToAssertionCount(1);
    }

    public function testVerifyThrowsForUnsupportedType(): void
    {
        $simulator = $this->simulator();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unexpected argument type');

        (new ArgumentVerifier())->verify($simulator, new DefaultCallingConvention(), null, 'fn');
    }
}
