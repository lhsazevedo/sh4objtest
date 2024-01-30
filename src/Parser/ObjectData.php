<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Parser;

use Lhsazevedo\Sh4ObjTest\BinaryReader;

class ObjectData
{
    public int $ukn1;

    public int $address;

    public int $length;

    public string $data;

    public function __construct(BinaryReader $reader)
    {
        $this->ukn1 = $reader->readUInt8();
        $this->address = $reader->readUInt32BE();
        $this->length = $reader->readUInt8();

        $data = $reader->readBytes($this->length);
        $this->data = $data;
    }
}
