<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest;

class BinaryReader
{
    /** @var resource */
    private $handle;

    public function __construct(string $file)
    {
        $fh = @fopen($file, "r+");
        if ($fh === false) {
            throw new \RuntimeException("Could not open file: $file");
        }

        $this->handle = $fh;
    }

    public function readUInt8(): int
    {
        $data = fread($this->handle, 1);
        $unpacked = unpack("C", $data);
        return $unpacked[1];
    }

    public function readUInt16(): int
    {
        $data = fread($this->handle, 2);
        $unpacked = unpack("v", $data);
        return $unpacked[1];
    }

    public function readUInt16BE(): int
    {
        $data = fread($this->handle, 2);
        $unpacked = unpack("n", $data);
        return $unpacked[1];
    }

    public function readUInt32(): int
    {
        $data = fread($this->handle, 4);
        $unpacked = unpack("V", $data);
        return $unpacked[1];
    }

    public function readUInt32BE(): int
    {
        $data = fread($this->handle, 4);
        $unpacked = unpack("N", $data);
        return $unpacked[1];
    }

    public function readInt8(): int
    {
        $data = fread($this->handle, 1);
        $unpacked = unpack("c", $data);
        return $unpacked[1];
    }

    public function readBytes(int $bytes): string
    {
        $data = fread($this->handle, $bytes);
        return $data;
    }

    public function readFloat(): float
    {
        $data = fread($this->handle, 4);
        $unpacked = unpack("f", $data);
        return $unpacked[1];
    }

    public function readDouble(): float
    {
        $data = fread($this->handle, 8);
        $unpacked = unpack("d", $data);
        return $unpacked[1];
    }

    public function eat(int $bytes): string|false
    {
        return fread($this->handle, $bytes);
    }

    public function peekBytes(int $bytes): string|false
    {
        $pos = $this->tell();
        $bytes = $this->readBytes($bytes);
        $this->seek($pos);
        return $bytes;
    }

    public function tell(): int|false
    {
        return ftell($this->handle);
    }

    public function seek(int $offset): int
    {
        return fseek($this->handle, $offset);
    }

    public function feof(): bool
    {
        return feof($this->handle);
    }

    public function __destruct()
    {
        fclose($this->handle);
    }
}
