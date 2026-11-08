<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Simulator;

use Lhsazevedo\Sh4ObjTest\Simulator\Types\U16;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U32;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U8;

class BinaryMemory {

    private string $memory;

    /**
     * On-chip P4 area.
     *
     * @var array<int, string>
     */
    private array $p4 = [];

    private const P4_BASE = 0xe0000000;

    /** Size of the random tile repeated across memory when randomizing (see below). */
    private const RANDOM_TILE_SIZE = 65536;

    public function __construct(int $size, bool $randomize = true)
    {
        if ($randomize) {
            // Randomize a small tile instead of the full 16MB region.
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
        return U8::unpack($this->read($address, 1));
    }

    public function readUInt16(int $address): U16
    {
        return U16::unpack($this->read($address, 2));
    }

    public function readUInt32(int $address): U32
    {
        return U32::unpack($this->read($address, 4));
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
        $this->write($address, $value->bytes());
    }

    public function writeUInt16(int $address, U16 $value): void
    {
        $this->write($address, $value->bytes());
    }

    public function writeUInt32(int $address, U32 $value): void
    {
        $this->write($address, $value->bytes());
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

    private static function isP4Address(int $address): bool
    {
        return $address >= self::P4_BASE;
    }

    private function read(int $address, int $length): string
    {
        if (self::isP4Address($address)) {
            $data = '';
            for ($i = 0; $i < $length; $i++) {
                $data .= $this->p4[$address + $i]
                    ?? throw new \Exception("Read of unset on-chip address " . dechex($address + $i) . "; init it first", 1);
            }

            return $data;
        }

        // TODO
        if ($address >= strlen($this->memory)) {
            throw new \Exception("Out of bounds memory access at " . dechex($address), 1);
        }

        return substr($this->memory, $address, $length);
    }

    private function write(int $address, string $data): void
    {
        if (self::isP4Address($address)) {
            for ($i = 0; $i < strlen($data); $i++) {
                $this->p4[$address + $i] = $data[$i];
            }
            return;
        }

        $this->writeBytes($address, $data);
    }
}
