<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Simulator\SuperH4;

enum FloatingPointRegister
{
    case FR0;
    case FR1;
    case FR2;
    case FR3;
    case FR4;
    case FR5;
    case FR6;
    case FR7;
    case FR8;
    case FR9;
    case FR10;
    case FR11;
    case FR12;
    case FR13;
    case FR14;
    case FR15;

    /**
     * Return the index of the register.
     * Note that we are noting using a backed enum because SH4 has 2 banks for
     * floating point registers, although the second bank is not implemented yet.
     * 
     * @return int
     */
    public function index(): int
    {
        return match ($this) {
            self::FR0 => 0,
            self::FR1 => 1,
            self::FR2 => 2,
            self::FR3 => 3,
            self::FR4 => 4,
            self::FR5 => 5,
            self::FR6 => 6,
            self::FR7 => 7,
            self::FR8 => 8,
            self::FR9 => 9,
            self::FR10 => 10,
            self::FR11 => 11,
            self::FR12 => 12,
            self::FR13 => 13,
            self::FR14 => 14,
            self::FR15 => 15,
        };
    }
}
