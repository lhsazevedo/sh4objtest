<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions;

use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\FloatingPointRegister;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\GeneralRegister;

class DefaultCallingConvention implements CallingConvention
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
    private int $stackOffset = 0;
    private int $argumentIndex = 0;

    /**
     * @param ?int $variadicFixed Number of leading fixed arguments; args at
     * or past this position go on the stack regardless of free registers,
     * per the SHC ABI.
     */
    public function __construct(
        private ?int $variadicFixed = null,
    ) {}

    public function getNextArgumentStorage(ArgumentType $type): GeneralRegister|FloatingPointRegister|StackOffset
    {
        return match ($type) {
            ArgumentType::General => $this->getGeneralStorage(),
            ArgumentType::FloatingPoint => $this->getFloatStorage(),
        };
    }

    public function getNextArgumentStorageForValue(mixed $value): GeneralRegister|FloatingPointRegister|StackOffset
    {
        return match (true) {
            is_int($value) => $this->getGeneralStorage(),
            is_float($value) => $this->getFloatStorage(),
            default => throw new \Exception('Unsupported argument type'),
        };
    }

    private function getGeneralStorage(): GeneralRegister|StackOffset
    {
        $this->argumentIndex++;

        if ($this->variadicFixed !== null && $this->argumentIndex > $this->variadicFixed) {
            return $this->getStackStorage();
        }

        if ($this->generalIndex < count($this->generalRegisters)) {
            return $this->generalRegisters[$this->generalIndex++];
        }
        return $this->getStackStorage();
    }

    private function getFloatStorage(): FloatingPointRegister|StackOffset
    {
        $this->argumentIndex++;

        if ($this->variadicFixed !== null && $this->argumentIndex > $this->variadicFixed) {
            return $this->getStackStorage();
        }

        if ($this->floatIndex < count($this->floatRegisters)) {
            return $this->floatRegisters[$this->floatIndex++];
        }
        return $this->getStackStorage();
    }

    private function getStackStorage(): StackOffset
    {
        $offset = new StackOffset($this->stackOffset);
        $this->stackOffset += 4;
        return $offset;
    }
}

