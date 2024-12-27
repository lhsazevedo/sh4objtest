<?php
declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Simulator;

use Lhsazevedo\Sh4ObjTest\Simulator\Types\U32;

class SymbolTable {
    /**
     * @var Symbol[]
     */
    private array $symbols = [];

    /**
     * Add a symbol to the map.
     *
     * @param Symbol $symbol The symbol to add.
     */
    public function addSymbol(Symbol $symbol): void {
        $address = $symbol->address->value;
        $this->symbols[$address] = $symbol;
        ksort($this->symbols); // Ensure symbols are sorted by address
    }

    public function getSymbolForAddress(U32 $address): ?Symbol {
        $addressValue = $address->value;
        $prevSymbol = null;

        foreach ($this->symbols as $startAddress => $symbol) {
            if ($addressValue < $startAddress) {
                // Address is between prevAddress and startAddress
                return $prevSymbol;
            }
            $prevSymbol = $symbol;
        }

        // If the address is beyond the last symbol's range, return the last symbol
        return $prevSymbol;
    }

    public function getSymbolAtAddress(U32 $address): ?Symbol {
        return $this->symbols[$address->value] ?? null;
    }

    /**
     * @return Symbol[]
     */
    public function getSymbols(): array {
        return $this->symbols;
    }
}
