<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Tests\Parser;

use Lhsazevedo\Sh4ObjTest\BinaryReader;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\UnitHeader;
use Lhsazevedo\Sh4ObjTest\Parser\DebugSymbol;
use PHPUnit\Framework\TestCase;

class UnitHeaderTest extends TestCase
{
    public function testCompilerDebugNamesGetUnderscorePrefix(): void
    {
        $unit = self::unit('C_SH');

        $this->assertSame('_getBit', $unit->linkedNameOf(self::staticSymbol(3, 'getBit')));
    }

    public function testAssemblerDebugNamesAreVerbatim(): void
    {
        $unit = self::unit('A_SH');

        $this->assertSame('_getBit', $unit->linkedNameOf(self::staticSymbol(1, '_getBit')));
    }

    private static function unit(string $tool): UnitHeader
    {
        return new UnitHeader(new BinaryReader(
            "\x00\x00\x01\x00\x00\x00\x00"
            . "\x04unit" . chr(strlen($tool)) . $tool . str_repeat('0', 12)
        ));
    }

    /** An AINFO_STATIC_INT record in section 0 at offset 0x80. */
    private static function staticSymbol(int $type, string $name): DebugSymbol
    {
        return new DebugSymbol(new BinaryReader(
            chr(($type << 1) | 1) . "\x00\x01" . chr(strlen($name)) . $name . "\x00\x00"
            . "\x04\x00\x00\x00\x00\x00\x00\x00\x00\x00\x80"
            . "\x00\x00\x00\x01\x00\x00"
        ));
    }
}
