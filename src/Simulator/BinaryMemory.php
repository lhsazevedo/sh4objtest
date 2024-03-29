<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Simulator;

class BinaryMemory {

    private string $memory;

    public function __construct(int $size)
    {
        $this->memory = str_repeat("\x0", $size);
    }

    public function readUInt16(int $address): int
    {
        $data = substr($this->memory, $address, 2);
        $unpacked = unpack("v", $data);
        return $unpacked[1];
    }

    public function readUInt32(int $address): int
    {
        // TODO
        if ($address >= strlen($this->memory)) {
            throw new \Exception("Out of bounds memory access at " . dechex($address), 1);
        }

        $data = substr($this->memory, $address, 4);
        $unpacked = unpack("V", $data);
        return $unpacked[1];
    }

    public function readString(int $address): string
    {
        $string = '';

        while(($char = $this->readUint8($address++)) !== 0) {
            $string .= chr($char);
        }

        return $string;
    }

    public function writeUInt8(int $address, int $value): void
    {
        $this->memory[$address] = pack('C', $value);
    }

    public function writeUInt16(int $address, int $value): void
    {
        $data = pack('v', $value);
        $this->memory[$address] = $data[0] ?? "\0";
        $this->memory[$address + 1] = $data[1] ?? "\0";
    }

    public function writeUInt32(int $address, int $value): void
    {
        $data = pack('V', $value);
        $this->memory[$address] = $data[0] ?? "\0";
        $this->memory[$address + 1] = $data[1] ?? "\0";
        $this->memory[$address + 2] = $data[2] ?? "\0";
        $this->memory[$address + 3] = $data[3] ?? "\0";
    }

    public function writeBytes(int $address, string $data): void
    {
        for ($i = 0; $i < strlen($data); $i++) { 
            $this->memory[$address + $i] = $data[$i];
        }
    }

    public function readUint8(int $address): int
    {
        $data = substr($this->memory, $address, 1);
        $unpacked = unpack("C", $data);
        return $unpacked[1];
    }
}
