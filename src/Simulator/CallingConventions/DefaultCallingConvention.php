<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions;

use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\FloatingPointRegister;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\GeneralRegister;

class DefaultCallingConvention implements CallingConvention, VariadicCallingConvention
{
    /** @var GeneralRegister[] */
    private array $generalRegisters = [
        GeneralRegister::R4,
        GeneralRegister::R5,
        GeneralRegister::R6,
        GeneralRegister::R7,
    ];

    /** @var FloatingPointRegister[] */
    private array $floatRegisters = [
        FloatingPointRegister::FR4,
        FloatingPointRegister::FR5,
        FloatingPointRegister::FR6,
        FloatingPointRegister::FR7,
        FloatingPointRegister::FR8,
        FloatingPointRegister::FR9,
        FloatingPointRegister::FR10,
        FloatingPointRegister::FR11,
    ];

    private int $generalIndex = 0;
    private int $floatIndex = 0;
    private int $stackIndex = 0;
    private ?int $variadicFixed = null;

    public function variadic(int $fixed): static
    {
        $this->variadicFixed = $fixed;
        return $this;
    }

    public function takeArgumentStorage(ArgumentType $type): GeneralRegister|FloatingPointRegister|StackOffset
    {
        return match ($type) {
            ArgumentType::General => $this->takeStorage($this->generalRegisters, $this->generalIndex),
            ArgumentType::FloatingPoint => $this->takeStorage($this->floatRegisters, $this->floatIndex),
        };
    }

    public function takeArgumentStorageForValue(mixed $value): GeneralRegister|FloatingPointRegister|StackOffset
    {
        return match (true) {
            is_int($value) => $this->takeStorage($this->generalRegisters, $this->generalIndex),
            is_float($value) => $this->takeStorage($this->floatRegisters, $this->floatIndex),
            default => throw new \Exception('Unsupported argument type'),
        };
    }

    /**
     * @param GeneralRegister[]|FloatingPointRegister[] $registers
     */
    private function takeStorage(array $registers, int &$index): GeneralRegister|FloatingPointRegister|StackOffset
    {
        if ($this->variadicFixed !== null && $this->argumentIndex() >= $this->variadicFixed) {
            return $this->takeStackStorage();
        }

        if ($index < count($registers)) {
            return $registers[$index++];
        }

        return $this->takeStackStorage();
    }

    private function argumentIndex(): int
    {
        return $this->generalIndex + $this->floatIndex + $this->stackIndex;
    }

    private function takeStackStorage(): StackOffset
    {
        return new StackOffset(4 * $this->stackIndex++);
    }
}

