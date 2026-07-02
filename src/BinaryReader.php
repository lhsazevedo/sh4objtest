<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest;

class BinaryReader
{
    private int $pos = 0;

    public function __construct(private string $data)
    {
    }

    public static function fromFile(string $path): self
    {
        $data = file_get_contents($path);
        if ($data === false) {
            throw new \RuntimeException("Could not open file: $path");
        }
        return new self($data);
    }

    public function readUInt8(): int
    {
        return unpack('C', $this->data[$this->pos++])[1];
    }

    public function readUInt16(): int
    {
        $v = unpack('v', substr($this->data, $this->pos, 2))[1];
        $this->pos += 2;
        return $v;
    }

    public function readUInt16BE(): int
    {
        $v = unpack('n', substr($this->data, $this->pos, 2))[1];
        $this->pos += 2;
        return $v;
    }

    public function readUInt32(): int
    {
        $v = unpack('V', substr($this->data, $this->pos, 4))[1];
        $this->pos += 4;
        return $v;
    }

    public function readUInt32BE(): int
    {
        $v = unpack('N', substr($this->data, $this->pos, 4))[1];
        $this->pos += 4;
        return $v;
    }

    public function readInt8(): int
    {
        $v = unpack('c', $this->data[$this->pos])[1];
        $this->pos++;
        return $v;
    }

    public function readBytes(int $bytes): string
    {
        $v = substr($this->data, $this->pos, $bytes);
        $this->pos += $bytes;
        return $v;
    }

    public function readFloat(): float
    {
        $v = unpack('f', substr($this->data, $this->pos, 4))[1];
        $this->pos += 4;
        return $v;
    }

    public function readDouble(): float
    {
        $v = unpack('d', substr($this->data, $this->pos, 8))[1];
        $this->pos += 8;
        return $v;
    }

    public function eat(int $bytes): string
    {
        $v = substr($this->data, $this->pos, $bytes);
        $this->pos += $bytes;
        return $v;
    }

    public function peekBytes(int $bytes): string
    {
        return substr($this->data, $this->pos, $bytes);
    }

    public function tell(): int
    {
        return $this->pos;
    }

    public function seek(int $offset): void
    {
        $this->pos = $offset;
    }

    public function feof(): bool
    {
        return $this->pos >= strlen($this->data);
    }

    public function remaining(): int
    {
        return max(0, strlen($this->data) - $this->pos);
    }

    public function eatRest(): string
    {
        return $this->eat($this->remaining());
    }
}
