<?php

declare(strict_types=1);

namespace Lhsazevedo\Objsim\Simulator;

class BinaryMemory {

    private string $memory;

    public function __construct(int $size)
    {
        $this->memory = str_repeat('0', $size);
    }

    public function readUInt16(int $address): int
    {
        $data = substr($this->memory, $address, 2);
        $unpacked = unpack("v", $data);
        return $unpacked[1];
    }

    public function readUInt32(int $address): int
    {
        $data = substr($this->memory, $address, 4);
        $unpacked = unpack("V", $data);
        return $unpacked[1];
    }

    public function writeUint32(int $address, int $value)
    {
        $data = pack('V', $value)[1];
        $this->memory[$address] = $data[0] ?? "\0";
        $this->memory[$address + 1] = $data[1] ?? "\0";
        $this->memory[$address + 2] = $data[2] ?? "\0";
        $this->memory[$address + 3] = $data[3] ?? "\0";
    }

    public function writeBytes(int $address, string $data)
    {
        for ($i = 0; $i < strlen($data); $i++) { 
            $this->memory[$address + $i] = $data[$i];
        }
    }
}   
