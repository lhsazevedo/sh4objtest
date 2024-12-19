<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Simulator;

use Lhsazevedo\Sh4ObjTest\Simulator\Types\U16;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U32;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U8;

class BinaryMemory {

    private string $memory;

    public function __construct(int $size, bool $randomize = true)
    {
        if ($randomize) {
            $this->memory = random_bytes($size);
            return;
        }

        $this->memory = str_repeat("\x0", $size);
    }

    public function readUInt8(int $address): U8
    {
        // TODO
        if ($address >= strlen($this->memory)) {
            throw new \Exception("Out of bounds memory access at " . dechex($address), 1);
        }

        $data = substr($this->memory, $address, 1);
        return U8::unpack($data);
    }

    public function readUInt16(int $address): U16
    {
        // TODO
        if ($address >= strlen($this->memory)) {
            throw new \Exception("Out of bounds memory access at " . dechex($address), 1);
        }

        $data = substr($this->memory, $address, 2);
        return U16::unpack($data);
    }

    public function readUInt32(int $address): U32
    {
        // TODO
        if ($address >= strlen($this->memory)) {
            throw new \Exception("Out of bounds memory access at " . dechex($address), 1);
        }

        $data = substr($this->memory, $address, 4);
        return U32::unpack($data);
    }

    public function readString(int $address): string
    {
        $string = '';

        while(($char = $this->readUint8($address++)->value) !== 0) {
            $string .= chr($char);
        }

        return $string;
    }

    public function writeUInt8(int $address, U8 $value): void
    {
        $this->memory[$address] = $value->bytes();
    }

    public function writeUInt16(int $address, U16 $value): void
    {
        $data = $value->bytes();
        $this->memory[$address + 0] = $data[0] ?? "\0";
        $this->memory[$address + 1] = $data[1] ?? "\0";
    }

    public function writeUInt32(int $address, U32 $value): void
    {
        $data = $value->bytes();
        $this->memory[$address + 0] = $data[0] ?? "\0";
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
}
