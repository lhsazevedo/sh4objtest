<?php

namespace Lhsazevedo\Sh4ObjTest\Parser;

class Chunk {
    public ?ChunkType $type;

    public function __construct(
        int $type,
        public string $data,
    ) {
        $this->type = ChunkType::tryFrom($type & 0x7f);
    }
}
