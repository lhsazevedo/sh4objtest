<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Parser;

enum ChunkType: int {
    case ModuleHeader    = 0x04;
    case UnitHeader      = 0x06;
    case UnitDebug       = 0x07;
    case SectionHeader   = 0x08;
    case Imports         = 0x0c;
    case Exports         = 0x14;
    case SectionSelection = 0x1a;
    case ObjectData      = 0x1c;
    case Relocation      = 0x20;
    case DebugLines      = 0x38;
    case Termination     = 0x7f;
}
