<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Simulator\Types;

define('U16_MAX_VALUE', 0xFFFF);

abstract readonly class UInt
{
    public const BIT_COUNT = 0;

    public const MAX_VALUE = 0;

    public const MIN_VALUE = 0;

    public const PACK_FORMAT = '';

    public final function __construct(
        public int $value,
    )
    {
        if ($value < static::MIN_VALUE || $value > static::MAX_VALUE) {
            throw new \InvalidArgumentException(
                static::class .' value must be between ' . static::MIN_VALUE . ' and ' . static::MAX_VALUE . '. Got: ' . $value
            );
        }
    }

    public final static function of(int $value): static
    {
        return new static($value);
    }

    public final static function unpack(string $data): static
    {
        $unpacked = unpack(static::PACK_FORMAT, $data);
        return new static($unpacked[1]);
    }

    public function signedValue(): int
    {
        if ($this->value & 1 << (static::BIT_COUNT - 1)) {
            return -((~$this->value & static::MAX_VALUE) + 1);
        }

        return $this->value;
    }

    /**
     * @param static|int $other
     */
    public function add($other): static
    {
        $other = $this->other($other);
        $result = $this->value + $other->value;
        return new static($result & static::MAX_VALUE);
    }

    /**
     * @param static|int $other
     */
    public function sub(self|int $other): static
    {
        $other = $this->other($other);
        $result = $this->value - $other->value;
        return new static($result & static::MAX_VALUE);
    }

    /**
     * @param static|int $other
     */
    public function mul(self|int $other): static
    {
        $other = $this->other($other);
        $result = $this->value * $other->value;
        return new static($result & static::MAX_VALUE);
    }

    /**
     * @param static|int $other
     */
    public function div(self|int $other): static
    {
        $other = $this->other($other);
        $result = intdiv($this->value, $other->value);
        return new static($result & static::MAX_VALUE);
    }

    public function invert(): static
    {
        $result = static::of(0)->sub($this->value)->value;
        return new static($result & static::MAX_VALUE);
    }

    public function mod(self|int $other): static
    {
        $other = $this->other($other);
        $result = $this->value % $other->value;
        return new static($result & static::MAX_VALUE);
    }

    /**
     * @param static|int $other
     */
    public function band(self|int $other): static
    {
        $other = $this->other($other);
        $result = $this->value & $other->value;
        return new static($result);
    }

    /**
     * @param static|int $other
     */
    public function bor(self|int $other): static
    {
        $other = $this->other($other);
        $result = $this->value | $other->value;
        return new static($result);
    }

    public function shiftLeft(int $shift): static
    {
        $result = $this->value << $shift;
        return new static($result & static::MAX_VALUE);
    }

    public function shiftRight(int $shift): static
    {
        $result = $this->value >> $shift;
        return new static($result & static::MAX_VALUE);
    }

    public function equals(self|int $other): bool
    {
        $other = $this->other($other);
        return $this->value === $other->value;
    }

    public function greaterThan(self|int $other): bool
    {
        $other = $this->other($other);
        return $this->value > $other->value;
    }

    public function lessThan(self|int $other): bool
    {
        $other = $this->other($other);
        return $this->value < $other->value;
    }

    public function greaterThanOrEqual(self|int $other): bool
    {
        $other = $this->other($other);
        return $this->value >= $other->value;
    }

    public function lessThanOrEqual(self|int $other): bool
    {
        $other = $this->other($other);
        return $this->value <= $other->value;
    }

    public function u32(): U32
    {
        return U32::of($this->value);
    }

    /**
     * Truncate the value to 8 bits
     */
    public function trunc8(): U8
    {
        if (static::BIT_COUNT === U8::BIT_COUNT) {
            throw new \RuntimeException('Cannot truncate to U8. Type is already U8');
        }

        if (static::BIT_COUNT <= U8::BIT_COUNT) {
            throw new \RuntimeException('Cannot truncate to U8. Type is greater than U8');
        }

        return U8::of($this->value & U8::MAX_VALUE);
    }

    /**
     * Truncate the value to 8 bits
     */
    public function trunc16(): U16
    {
        if (static::BIT_COUNT === U16::BIT_COUNT) {
            throw new \RuntimeException('Cannot truncate to U16. Type is already U16');
        }

        if (static::BIT_COUNT <= U16::BIT_COUNT) {
            throw new \RuntimeException('Cannot truncate to U16. Type is greater than U16');
        }

        return U16::of($this->value & U16::MAX_VALUE);
    }

    /**
     * Sign extend value to 32 bits
     */
    public function extend32(): U32
    {
        $value = $this->value;

        // Extend if the sign bit is set
        if ($this->value & (1 << (static::BIT_COUNT - 1))) {
            $mask = U32::MAX_VALUE ^ static::MAX_VALUE;
            $value |= $mask;
        }

        return new U32($value);
    }

    public function pack(): string
    {
        return pack(static::PACK_FORMAT, $this->value);
    }

    public function bytes(): string
    {
        return $this->pack();
    }

    public function hex(): string
    {
        return str_pad(dechex($this->value), static::BIT_COUNT / 4, '0', STR_PAD_LEFT);
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }

    public function readable(): string
    {
        return 'U' . static::BIT_COUNT . ": $this->value (0x{$this->hex()})";
    }

    /**
     * @param static|int $other
     */
    protected function other(self|int $other): static
    {
        if (is_int($other)) {
            return new static($other);
        }

        if (!($other instanceof static)) {
            throw new \InvalidArgumentException('Invalid type' . get_class($other) . '. Expected ' . static::class);
        }

        return $other;
    }
}
