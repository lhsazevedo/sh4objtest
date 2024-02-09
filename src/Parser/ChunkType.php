<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Parser;

enum ChunkType {
    case ModuleHeader;
    case UnitHeader;
    case UnitDebug;
    case SectionHeader;
    case Imports;
    case Exports;
    case SectionSelection;
    case ObjectData;
    case Relocation;
    case Termination;
    case Unknown;
}
