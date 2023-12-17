<?php

declare(strict_types=1);

namespace Lhsazevedo\Objsim;

class BinaryReader
{
    private $handle;

    public function __construct($file)
    {
        $this->handle = fopen($file, "r+");
        // fwrite($this->handle, $binaryData);
        // rewind($this->handle);
    }

    public function readUInt8()
    {
        $data = fread($this->handle, 1);
        $unpacked = unpack("C", $data);
        return $unpacked[1];
    }

    public function readUInt16()
    {
        $data = fread($this->handle, 2);
        $unpacked = unpack("v", $data);
        return $unpacked[1];
    }

    public function readUInt32()
    {
        $data = fread($this->handle, 4);
        $unpacked = unpack("V", $data);
        return $unpacked[1];
    }

    public function readInt8()
    {
        $data = fread($this->handle, 1);
        $unpacked = unpack("c", $data);
        return $unpacked[1];
    }

    public function readInt16()
    {
        $data = fread($this->handle, 2);
        $unpacked = unpack("v", $data);
        return $unpacked[1];
    }

    public function readInt32()
    {
        $data = fread($this->handle, 4);
        $unpacked = unpack("V", $data);
        return $unpacked[1];
    }

    public function readBytes($bytes)
    {
        $data = fread($this->handle, $bytes);
        return $data;
    }

    public function readFloat()
    {
        $data = fread($this->handle, 4);
        $unpacked = unpack("f", $data);
        return $unpacked[1];
    }

    public function readDouble()
    {
        $data = fread($this->handle, 8);
        $unpacked = unpack("d", $data);
        return $unpacked[1];
    }

    public function eat(int $bytes): string|false
    {
        return fread($this->handle, $bytes);
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
