<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Tests\Simulator\Types;

use Lhsazevedo\Sh4ObjTest\Simulator\Types\U32;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UIntTest extends TestCase
{
    #[DataProvider('shiftLeftProvider')]
    public function testShiftLeftDiscardsHighBits(int $value, int $shift, int $expected): void
    {
        $this->assertSame($expected, U32::of($value)->shiftLeft($shift)->value);
    }

    /** @return array<string, array{int, int, int}> */
    public static function shiftLeftProvider(): array
    {
        return [
            // SHLL2 on 0xffffffff: bits shifted out are discarded, no overflow trap.
            'drops high bits' => [0xffffffff, 2, 0xfffffffc],
            'default shift of one' => [0x40000000, 1, 0x80000000],
            'msb shifted out' => [0x80000000, 1, 0x00000000],
            'no-op zero' => [0, 4, 0],
        ];
    }

    public function testShiftRight(): void
    {
        $this->assertSame(0x3fffffff, U32::of(0xffffffff)->shiftRight(2)->value);
    }
}
