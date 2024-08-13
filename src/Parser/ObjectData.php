<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Parser;

use Lhsazevedo\Sh4ObjTest\BinaryReader;

class ObjectData
{
    public bool $hasStartingAddress;

    public bool $isCompressed;

    public int $address;

    public int $length;

    public string $data;

    public function __construct(BinaryReader $reader)
    {
        $flags = $reader->readUInt8();
        $this->hasStartingAddress = ($flags & 0x80) !== 0;
        $this->isCompressed = ($flags & 0x40) !== 0;

        if (!$this->hasStartingAddress) {
            throw new \Exception("Object data without starting address is not supported.");
        }
        $this->address = $reader->readUInt32BE();

        $repetitions = $this->isCompressed ? $reader->readUInt32BE() : 0;

        $this->length = $reader->readUInt8();

        $data = $reader->readBytes($this->length);

        if ($this->isCompressed) {
            $data = str_repeat($data, $repetitions);
        }

        $this->data = $data;
    }
}
