<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Tests\Simulator;

use Lhsazevedo\Sh4ObjTest\Simulator\Symbol;
use Lhsazevedo\Sh4ObjTest\Simulator\SymbolTable;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U32;
use PHPUnit\Framework\TestCase;

class SymbolTableTest extends TestCase
{
    public function testFirstSymbolAddedAtAnAddressWins(): void
    {
        $symbols = new SymbolTable();
        $symbols->addSymbol(new Symbol('_ReplayCodecInit', U32::of(0x100), callable: true));
        $symbols->addSymbol(new Symbol('P', U32::of(0x100), callable: false));

        $symbol = $symbols->getSymbolAtAddress(U32::of(0x100));

        $this->assertSame('_ReplayCodecInit', $symbol?->name);
        $this->assertTrue($symbol->callable);
    }
}
