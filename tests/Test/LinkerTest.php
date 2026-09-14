<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Tests\Test;

use Lhsazevedo\Sh4ObjTest\BinaryReader;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\ExportSymbol;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\SectionHeader;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\UnitHeader;
use Lhsazevedo\Sh4ObjTest\Parser\DebugSymbol;
use Lhsazevedo\Sh4ObjTest\Parser\ParsedObject;
use Lhsazevedo\Sh4ObjTest\Parser\Stype;
use Lhsazevedo\Sh4ObjTest\Simulator\Symbol;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U32;
use Lhsazevedo\Sh4ObjTest\Test\Linker;
use PHPUnit\Framework\TestCase;

class LinkerTest extends TestCase
{
    private const CODE = 0;
    private const DATA = 1;

    /** Linked address of the data section. */
    private const DATA_BASE = 0x1000;

    public function testCompilerFunctionIsCallable(): void
    {
        $unit = self::unit('C_SH');
        $unit->addDebugSymbol(self::staticSymbol(Stype::Func, 'getBit', self::CODE, 0x10));

        $this->assertTrue(self::symbolAt($unit, 0x10)->callable);
    }

    public function testCompilerLabelIsNotCallable(): void
    {
        $unit = self::unit('C_SH');
        $unit->addDebugSymbol(self::staticSymbol(Stype::Label, 'gambi', self::CODE, 0x10));

        $this->assertFalse(self::symbolAt($unit, 0x10)->callable);
    }

    public function testAssemblerLabelIsCallable(): void
    {
        $unit = self::unit('A_SH');
        $unit->addDebugSymbol(self::staticSymbol(Stype::Label, '_getBit', self::CODE, 0x10));

        $this->assertTrue(self::symbolAt($unit, 0x10, ['/^LAB_/'])->callable);
    }

    public function testAssemblerLabelMatchingBlocklistIsNotCallable(): void
    {
        $unit = self::unit('A_SH');
        $unit->addDebugSymbol(self::staticSymbol(Stype::Label, 'LAB_8c02f330', self::CODE, 0x10));

        $this->assertFalse(self::symbolAt($unit, 0x10, ['/^LAB_/'])->callable);
    }

    public function testDataSymbolIsNotCallable(): void
    {
        $unit = self::unit('A_SH');
        $unit->addDebugSymbol(self::staticSymbol(Stype::Label, '_var_bitBuf', self::DATA, 0x10));

        $symbol = self::symbolAt($unit, self::DATA_BASE + 0x10);

        $this->assertSame('_var_bitBuf', $symbol->name);
        $this->assertFalse($symbol->callable);
    }

    public function testExportWinsOverDebugSymbolAtSameAddress(): void
    {
        $unit = self::unit('A_SH');
        $unit->sections[self::CODE]->addExport(new ExportSymbol('_ReplayCodecInit', self::CODE, 0, 0));
        $unit->addDebugSymbol(self::staticSymbol(Stype::Label, 'P', self::CODE, 0));

        $this->assertSame('_ReplayCodecInit', self::symbolAt($unit, 0)->name);
    }

    /** @param string[] $callBlocklist */
    private static function symbolAt(UnitHeader $unit, int $address, array $callBlocklist = []): Symbol
    {
        $unit->sections[self::CODE]->rellocate(0);
        $unit->sections[self::DATA]->rellocate(self::DATA_BASE);

        $program = (new Linker())->link(new ParsedObject($unit), '', [], $callBlocklist);
        $symbol = $program->symbols->getSymbolAtAddress(U32::of($address));
        self::assertNotNull($symbol);

        return $symbol;
    }

    private static function unit(string $tool): UnitHeader
    {
        $unit = new UnitHeader(new BinaryReader(
            "\x00\x00\x02\x00\x00\x00\x00"
            . "\x04unit" . chr(strlen($tool)) . $tool . str_repeat('0', 12)
        ));
        $unit->addSection(self::section("\x00", 'P'));
        $unit->addSection(self::section("\x10", 'B'));

        return $unit;
    }

    private static function section(string $contents, string $name): SectionHeader
    {
        return new SectionHeader(new BinaryReader(
            "\x00" . pack('NNN', 0, 0x100, 4) . $contents . "\x00\x00" . chr(strlen($name)) . $name
        ));
    }

    /** An AINFO_STATIC_INT record. */
    private static function staticSymbol(Stype $type, string $name, int $section, int $address): DebugSymbol
    {
        return new DebugSymbol(new BinaryReader(
            chr(($type->value << 1) | 1) . "\x00\x01" . chr(strlen($name)) . $name . "\x00\x00"
            . "\x04\x00\x00\x00\x00" . pack('nN', $section, $address)
            . "\x00\x00\x00\x01\x00\x00"
        ));
    }
}
