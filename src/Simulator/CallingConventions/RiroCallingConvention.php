<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions;

use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\FloatingPointRegister;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\GeneralRegister;

/**
 * Operands in R1 then R0. The layout SHC uses for its integer div/mod runtime
 * routines (__divls/__divlu/__modls/__modlu): dividend in R1, divisor in R0.
 */
class RiroCallingConvention implements CallingConvention
{
    /** @var GeneralRegister[] */
    private array $generalRegisters = [
        GeneralRegister::R1,
        GeneralRegister::R0,
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
