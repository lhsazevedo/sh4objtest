<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Parser;

/** Debug symbol kind (the "symbol type" field of a DebugSymbol record). */
enum Stype: int {
    case Var       = 0;
    case Label     = 1;
    case Proc      = 2;
    case Func      = 3;
    case Type      = 4;
    case Const     = 5;
    case Entry     = 6;
    case Member    = 7;
    case Enum      = 8;
    case Tag       = 9;
    case Package   = 10;
    case Generic   = 11;
    case Task      = 12;
    case Exception = 13;
    case Parameter = 14;
    case Equate    = 15;
    case Unspec    = 0x7f;
}
