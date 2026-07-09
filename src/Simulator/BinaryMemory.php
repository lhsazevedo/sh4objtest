<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Simulator;

use Lhsazevedo\Sh4ObjTest\Simulator\Types\U16;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U32;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U8;

class BinaryMemory {

    private string $memory;

    /** Size of the random tile repeated across memory when randomizing (see below). */
    private const RANDOM_TILE_SIZE = 65536;

    public function __construct(int $size, bool $randomize = true)
    {
        if ($randomize) {
            // random_bytes() over the full (typically 16MB) region dominated
            // suite runtime (CSPRNG throughput, not memory bandwidth). Tests
            // only need non-zero "garbage" to catch code relying on
            // zero-initialized memory, not cryptographic randomness, so tile
            // a small random chunk instead.
            $tile = random_bytes(self::RANDOM_TILE_SIZE);
            $reps = intdiv($size, self::RANDOM_TILE_SIZE);
            $remainder = $size % self::RANDOM_TILE_SIZE;
            $this->memory = $remainder === 0
                ? str_repeat($tile, $reps)
                : str_repeat($tile, $reps) . substr($tile, 0, $remainder);
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

    public function readBytes(int $address, int $length): string
    {
        return substr($this->memory, $address, $length);
    }

    public function writeBytes(int $address, string $data): void
    {
        for ($i = 0; $i < strlen($data); $i++) { 
            $this->memory[$address + $i] = $data[$i];
        }
    }
}
