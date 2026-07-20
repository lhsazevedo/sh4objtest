<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Tests\Simulator;

use Lhsazevedo\Sh4ObjTest\Simulator\BinaryMemory;
use Lhsazevedo\Sh4ObjTest\Simulator\Simulator;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U16;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U32;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SimulatorTest extends TestCase
{
    #[DataProvider('shldProvider')]
    public function testShld(int $value, int $shiftAmount, int $expected): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));

        // SHLD R4,R5 (0100nnnnmmmm1101, n=5, m=4)
        $simulator->setRegister(5, U32::of($value));
        $simulator->setRegister(4, U32::of($shiftAmount));

        $simulator->executeInstruction(U16::of(0x454d));

        $this->assertSame($expected, $simulator->getRegister(5)->value);
    }

    /** @return array<string, array{int, int, int}> */
    public static function shldProvider(): array
    {
        return [
            'left shift by positive amount' => [0x00000001, 4, 0x00000010],
            'right shift by negative amount' => [0x80000000, -4 & 0xffffffff, 0x08000000],
            // Shift amount <= -32 (only low 5 bits considered, and they're zero): zero-fill.
            'full right shift zeroes out' => [0xffffffff, -32 & 0xffffffff, 0x00000000],
        ];
    }

    #[DataProvider('cmpHiProvider')]
    public function testCmpHi(int $rn, int $rm, int $expectedT): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));

        // CMP/HI R1,R2 (0011nnnnmmmm0110, n=2, m=1): T = 1 if unsigned R2 > R1.
        $simulator->setRegister(2, U32::of($rn));
        $simulator->setRegister(1, U32::of($rm));
        $simulator->executeInstruction(U16::of(0x3216));

        // MOVT R3 (0000nnnn00101001, n=3): store T into R3 so we can observe it.
        $simulator->executeInstruction(U16::of(0x0329));

        $this->assertSame($expectedT, $simulator->getRegister(3)->value);
    }

    /** @return array<string, array{int, int, int}> */
    public static function cmpHiProvider(): array
    {
        return [
            'greater is true' => [5, 3, 1],
            'equal is false' => [3, 3, 0],
            'less is false' => [3, 5, 0],
            // Unsigned: 0x80000000 outranks 1 even though it is negative as signed.
            'high bit set compares unsigned' => [0x80000000, 0x00000001, 1],
        ];
    }
}
