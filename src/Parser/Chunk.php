<?php

namespace Lhsazevedo\Sh4ObjTest\Parser;

class Chunk {
    public ?ChunkType $type;

    public int $rawType;

    public function __construct(
        int $type,
        public string $data,
        public int $offset = 0,
    ) {
        $this->rawType = $type & 0x7f;
        $this->type = ChunkType::tryFrom($this->rawType);
    }
}
