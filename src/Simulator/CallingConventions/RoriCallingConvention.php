<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions;

use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\FloatingPointRegister;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\GeneralRegister;

/**
 * Operands in R0 then R1. The layout SHC uses for its string runtime routines
 * (__slow_strcpy/__slow_strcmp1): dest in R0, src in R1.
 */
class RoriCallingConvention implements CallingConvention
{
    /** @var GeneralRegister[] */
    private array $generalRegisters = [
        GeneralRegister::R0,
        GeneralRegister::R1,
    ];

    private int $generalIndex = 0;

    public function takeArgumentStorage(ArgumentType $type): GeneralRegister|FloatingPointRegister|StackOffset
    {
        if ($type !== ArgumentType::General) {
            throw new \Exception('Runtime routines only take general arguments');
        }

        if ($this->generalIndex >= count($this->generalRegisters)) {
            throw new \Exception('Runtime routines take at most two arguments');
        }

        return $this->generalRegisters[$this->generalIndex++];
    }

    public function takeArgumentStorageForValue(mixed $value): GeneralRegister|FloatingPointRegister|StackOffset
    {
        if (!is_int($value)) {
            throw new \Exception('Runtime routines only take integer arguments');
        }

        return $this->takeArgumentStorage(ArgumentType::General);
    }
}
