<?php

declare(strict_types=1);

namespace Lhsazevedo\Objsim\Parser;

enum ChunkType {
    case ModuleHeader;
    case UnitHeader;
    case UnitDebug;
    case Section;
    case Imports;
    case Exports;
    case SectionSelection;
    case ObjectData;
    case Relocation;
    case Termination;
    case Unknown;
}
