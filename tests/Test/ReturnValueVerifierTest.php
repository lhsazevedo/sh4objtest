<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Tests\Test;

use Lhsazevedo\Sh4ObjTest\Simulator\BinaryMemory;
use Lhsazevedo\Sh4ObjTest\Simulator\Exceptions\ExpectationException;
use Lhsazevedo\Sh4ObjTest\Simulator\Simulator;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U32;
use Lhsazevedo\Sh4ObjTest\Test\ReturnValueVerifier;
use PHPUnit\Framework\TestCase;

class ReturnValueVerifierTest extends TestCase
{
    private function simulator(): Simulator
    {
        return new Simulator(new BinaryMemory(1024, randomize: false));
    }

    public function testIntReturnPasses(): void
    {
        $simulator = $this->simulator();
        $simulator->setRegister(0, U32::of(42));

        $message = (new ReturnValueVerifier())->verify($simulator, 42);

        $this->assertSame('Returned 42', $message);
    }

    public function testIntReturnThrowsOnMismatch(): void
    {
        $simulator = $this->simulator();
        $simulator->setRegister(0, U32::of(1));

        $this->expectException(ExpectationException::class);
        (new ReturnValueVerifier())->verify($simulator, 2);
    }

    public function testFloatReturnPasses(): void
    {
        $simulator = $this->simulator();
        $simulator->setFloatRegister(0, 1.5);

        $message = (new ReturnValueVerifier())->verify($simulator, 1.5);

        $this->assertSame('Returned float 1.5', $message);
    }

    public function testFloatReturnThrowsOnMismatch(): void
    {
        $simulator = $this->simulator();
        $simulator->setFloatRegister(0, 1.0);

        $this->expectException(ExpectationException::class);
        (new ReturnValueVerifier())->verify($simulator, 2.0);
    }
}
