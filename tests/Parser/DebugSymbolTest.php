<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Tests\Parser;

use Lhsazevedo\Sh4ObjTest\BinaryReader;
use Lhsazevedo\Sh4ObjTest\Parser\DebugSymbol;
use Lhsazevedo\Sh4ObjTest\Parser\Stype;
use PHPUnit\Framework\TestCase;

class DebugSymbolTest extends TestCase
{
    /**
     * A Parameter record for an AINFO_AUTO "task" at frame offset -8, ending in
     * the two undescribed trailing bytes. Taken from 01bb48_vm_game.obj.
     */
    private const AUTO_PARAM = "\x1d\x05\xf3\x04task\x00\x00"
        . "\x06\x00\x00\x00\x04\xff\xff\xff\xf8"
        . "\x00\x00\x01\x7e\x10\x01";

    public function testParameterWithoutRegisterFlag(): void
    {
        $symbol = new DebugSymbol(new BinaryReader(self::AUTO_PARAM . "\x00\x00"));

        $this->assertSame(Stype::Parameter, $symbol->type);
        $this->assertSame('task', $symbol->name);
        $this->assertNull($symbol->register);
        $this->assertFalse($symbol->variadic);
    }

    public function testVariadicParameter(): void
    {
        $symbol = new DebugSymbol(new BinaryReader(self::AUTO_PARAM . "\x01\x00"));

        $this->assertTrue($symbol->variadic);
        $this->assertNull($symbol->register);
    }

    public function testParameterFlagBit7CarriesArrivalRegister(): void
    {
        $symbol = new DebugSymbol(
            new BinaryReader(self::AUTO_PARAM . "\x00\xc0\x02R4")
        );

        $this->assertSame('task', $symbol->name);
        $this->assertSame(0xfffffff8, $symbol->address);
        $this->assertSame('R4', $symbol->register);
    }

    public function testParameterRecordIsFullyConsumed(): void
    {
        $reader = new BinaryReader(self::AUTO_PARAM . "\x00\xc0\x02R4");

        new DebugSymbol($reader);

        $this->assertTrue($reader->feof());
    }
}
