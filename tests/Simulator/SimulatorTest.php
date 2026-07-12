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
}
