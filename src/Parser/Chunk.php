<?php

namespace Lhsazevedo\Sh4ObjTest\Parser;

class Chunk {
    public ChunkType $type;

    public function __construct(
        int $type,
        public string $data,
    ) {
        $this->type = match ($type & 0x7f) {
            0x04 => ChunkType::ModuleHeader,
            0x06 => ChunkType::UnitHeader,
            0x07 => ChunkType::UnitDebug,
            0x08 => ChunkType::SectionHeader,
            0x0c => ChunkType::Imports,
            0x14 => ChunkType::Exports,
            0x1a => ChunkType::SectionSelection,
            0x1c => ChunkType::ObjectData,
            0x20 => ChunkType::Relocation,
            0x7f => ChunkType::Termination,
            default => ChunkType::Unknown,
        };
    }
}
