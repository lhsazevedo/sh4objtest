<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Simulator\SuperH4;

enum GeneralRegister
{
    case R0;
    case R1;
    case R2;
    case R3;
    case R4;
    case R5;
    case R6;
    case R7;
    case R8;
    case R9;
    case R10;
    case R11;
    case R12;
    case R13;
    case R14;
    case R15;

    /**
     * Return the index of the register.
     * Note that we are noting using backed enums because SH4 has 2 banks for
     * R0-R7, although the second bank is not implemented yet.
     * 
     * @return int
     */
    public function index(): int
    {
        return match ($this) {
            self::R0 => 0,
            self::R1 => 1,
            self::R2 => 2,
            self::R3 => 3,
            self::R4 => 4,
            self::R5 => 5,
            self::R6 => 6,
            self::R7 => 7,
            self::R8 => 8,
            self::R9 => 9,
            self::R10 => 10,
            self::R11 => 11,
            self::R12 => 12,
            self::R13 => 13,
            self::R14 => 14,
            self::R15 => 15,
        };
    }
}
