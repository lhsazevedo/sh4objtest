<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Tests\Simulator\CallingConventions;

use Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions\ArgumentType;
use Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions\DefaultCallingConvention;
use Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions\StackOffset;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\FloatingPointRegister;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\GeneralRegister;
use PHPUnit\Framework\TestCase;

class DefaultCallingConventionTest extends TestCase
{
    public function testNonVariadicFillsAllGeneralRegistersBeforeStack(): void
    {
        $convention = new DefaultCallingConvention();

        $this->assertSame(GeneralRegister::R4, $convention->getNextArgumentStorage(ArgumentType::General));
        $this->assertSame(GeneralRegister::R5, $convention->getNextArgumentStorage(ArgumentType::General));
        $this->assertSame(GeneralRegister::R6, $convention->getNextArgumentStorage(ArgumentType::General));
        $this->assertSame(GeneralRegister::R7, $convention->getNextArgumentStorage(ArgumentType::General));

        $storage = $convention->getNextArgumentStorage(ArgumentType::General);
        $this->assertInstanceOf(StackOffset::class, $storage);
        $this->assertSame(0, $storage->offset);
    }

    public function testVariadicArgumentsGoOnStackEvenWithFreeRegisters(): void
    {
        // e.g. sprintf(buf, fmt, ...): 2 fixed args, rest variadic.
        $convention = new DefaultCallingConvention(variadic: 2);

        $this->assertSame(GeneralRegister::R4, $convention->getNextArgumentStorage(ArgumentType::General));
        $this->assertSame(GeneralRegister::R5, $convention->getNextArgumentStorage(ArgumentType::General));

        // R6/R7 are still free, but this argument is variadic, so it must
        // land on the stack per the SHC ABI.
        $storage = $convention->getNextArgumentStorage(ArgumentType::General);
        $this->assertInstanceOf(StackOffset::class, $storage);
        $this->assertSame(0, $storage->offset);

        $storage = $convention->getNextArgumentStorage(ArgumentType::General);
        $this->assertInstanceOf(StackOffset::class, $storage);
        $this->assertSame(4, $storage->offset);
    }

    public function testVariadicCutoffCountsAcrossGeneralAndFloatArguments(): void
    {
        // Fixed args can mix int/float; the cutoff is a total argument
        // position, not per-register-pool.
        $convention = new DefaultCallingConvention(variadic: 2);

        $this->assertSame(GeneralRegister::R4, $convention->getNextArgumentStorage(ArgumentType::General));
        $this->assertSame(FloatingPointRegister::FR4, $convention->getNextArgumentStorage(ArgumentType::FloatingPoint));

        $storage = $convention->getNextArgumentStorage(ArgumentType::FloatingPoint);
        $this->assertInstanceOf(StackOffset::class, $storage);
        $this->assertSame(0, $storage->offset);
    }

    public function testZeroVariadicPutsAllArgumentsOnStack(): void
    {
        $convention = new DefaultCallingConvention(variadic: 0);

        $storage = $convention->getNextArgumentStorage(ArgumentType::General);
        $this->assertInstanceOf(StackOffset::class, $storage);
        $this->assertSame(0, $storage->offset);
    }
}
