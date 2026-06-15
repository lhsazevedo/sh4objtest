<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Parser;

enum ChunkType: int {
    case FileHeader      = 0x00;
    case ModuleHeader    = 0x04;
    case UnitHeader      = 0x06;
    case UnitDebug       = 0x07;
    case SectionHeader   = 0x08;
    case Imports         = 0x0c;
    case Exports         = 0x14;
    case SectionSelection = 0x1a;
    case ObjectData      = 0x1c;
    case Relocation      = 0x20;
    case DebugSymbol     = 0x34;
    case DebugLines      = 0x38;
    // "dus" (debug unit sub-info): the unit's source/include file table.
    case DebugSourceFiles = 0x40;
    case Termination     = 0x7f;
}
