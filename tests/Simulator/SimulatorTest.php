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

    /**
     * The R0-indexed effective address is 32-bit modular: a negative index such
     * as 0xfffffffc wraps base + 0xfffffffc back to base - 4 (e.g. arr[-1]),
     * matching SH4 hardware (and flycast's u32 addr+offset).
     *
     * @param int $r0 @param int $rm @param int $ea resolved effective address
     */
    #[DataProvider('r0IndexedProvider')]
    public function testMovLLoadR0Indexed(int $r0, int $rm, int $ea): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));
        $simulator->getMemory()->writeUInt32($ea, U32::of(0xdeadbeef));

        $simulator->setRegister(0, U32::of($r0));
        $simulator->setRegister(2, U32::of($rm));

        // MOV.L @(R0,R2),R1 (0000nnnnmmmm1110, n=1, m=2)
        $simulator->executeInstruction(U16::of(0x012e));

        $this->assertSame(0xdeadbeef, $simulator->getRegister(1)->value);
    }

    #[DataProvider('r0IndexedProvider')]
    public function testMovLStoreR0Indexed(int $r0, int $rm, int $ea): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));

        $simulator->setRegister(0, U32::of($r0));
        $simulator->setRegister(2, U32::of($rm));
        $simulator->setRegister(1, U32::of(0xdeadbeef));

        // MOV.L R1,@(R0,R2) (0000nnnnmmmm0110, n=2, m=1)
        $simulator->executeInstruction(U16::of(0x0216));

        $this->assertSame(0xdeadbeef, $simulator->getMemory()->readUInt32($ea)->value);
    }

    /** @return array<string, array{int, int, int}> */
    public static function r0IndexedProvider(): array
    {
        return [
            'positive index' => [0x40, 0xc0, 0x100],
            'negative index wraps' => [0x100, -4 & 0xffffffff, 0xfc],
        ];
    }

    #[DataProvider('mulsWProvider')]
    public function testMulsW(int $rn, int $rm, int $expectedMacl): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));

        $simulator->setRegister(2, U32::of($rn));
        $simulator->setRegister(1, U32::of($rm));

        // MULS.W R1,R2 (0010nnnnmmmm1111, n=2, m=1)
        $simulator->executeInstruction(U16::of(0x221f));

        // STS MACL,R3 (0000nnnn00011010, n=3): store MACL into R3 so we can observe it.
        $simulator->executeInstruction(U16::of(0x031a));

        $this->assertSame($expectedMacl, $simulator->getRegister(3)->value);
    }

    /** @return array<string, array{int, int, int}> */
    public static function mulsWProvider(): array
    {
        return [
            'positive times positive' => [100, 200, 20000],
            'negative times negative' => [-5 & 0xffffffff, -3 & 0xffffffff, 15],
            'positive times negative' => [1000, -2 & 0xffffffff, -2000 & 0xffffffff],
            // Only the low 16 bits of each register feed the multiply.
            'high bits of register are ignored' => [0x12340005, 0x00000002, 10],
            'largest magnitude operands' => [0xffff8000, 0xffff8000, 1073741824],
        ];
    }
}
