<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest;

use Lhsazevedo\Sh4ObjTest\Parser\ParsedObject;
use Lhsazevedo\Sh4ObjTest\Simulator\Arguments\WildcardArgument;
use Lhsazevedo\Sh4ObjTest\Test\Entry;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\CallCommand;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\CallExpectation;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\ReadExpectation;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\ReturnExpectation;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\StringWriteExpectation;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\WriteExpectation;
use Lhsazevedo\Sh4ObjTest\Test\MemoryInitialization;
use Lhsazevedo\Sh4ObjTest\Test\TestRelocation;

class TestCase
{
    protected ?string $objectFile = null;

    protected ParsedObject $parsedObject;

    private Entry $entry;

    /** @var \Lhsazevedo\Sh4ObjTest\Test\Expectations\AbstractExpectation[] */
    private $expectations = [];

    private bool $forceStop = false;

    private bool $randomizeMemory = true;

    private int $currentAlloc = 1024 * 1024 * 8;

    /** @var TestRelocation[] */
    private array $testRelocations = [];

    /** @var MemoryInitialization[] */
    private array $initializations = [];

    public function __construct()
    {
        $this->entry = new Entry();
    }

    protected function shouldCall(string|int $target): CallExpectation
    {
        // Assume target is address
        $address = $target;
        $name = null;

        if (is_string($target)) {
            $address = $this->addressOf($target);
            $name = $target;
        }

        $expectation = new CallExpectation($name, $address);
        $this->expectations[] = $expectation;

        return $expectation;
    }

    protected function shouldRead(int $address, int $value): ReadExpectation
    {
        return $this->shouldReadLong($address, $value);
    }

    protected function shouldReadLong(int $address, int $value): ReadExpectation
    {
        $expectation = new ReadExpectation($address, $value, 32);
        $this->expectations[] = $expectation;

        return $expectation;
    }

    protected function shouldReadWord(int $address, int $value): ReadExpectation
    {
        $expectation = new ReadExpectation($address, $value, 16);
        $this->expectations[] = $expectation;

        return $expectation;
    }

    protected function shouldReadByte(int $address, int $value): ReadExpectation
    {
        $expectation = new ReadExpectation($address, $value, 8);
        $this->expectations[] = $expectation;

        return $expectation;
    }

    /**
     * @todo: Use another expectation class for string literals
     */
    protected function shouldWrite(int $address, int|string $value): WriteExpectation
    {
        return $this->shouldWriteLong($address, $value);
    }

    protected function shouldWriteLong(int $address, int|string $value): WriteExpectation
    {
        $expectation = new WriteExpectation($address, $value, 32);
        $this->expectations[] = $expectation;

        return $expectation;
    }

    protected function shouldWriteWord(int $address, int|string $value): WriteExpectation
    {
        $expectation = new WriteExpectation($address, $value, 16);
        $this->expectations[] = $expectation;

        return $expectation;
    }

    protected function shouldWriteByte(int $address, int|string $value): WriteExpectation
    {
        $expectation = new WriteExpectation($address, $value, 8);
        $this->expectations[] = $expectation;

        return $expectation;
    }

    protected function shouldWriteString(int $address, string $value): StringWriteExpectation
    {
        $expectation = new StringWriteExpectation($address, $value);
        $this->expectations[] = $expectation;

        return $expectation;
    }

    protected function shouldWriteFloat(int $address, float $value): WriteExpectation
    {
        $expectation = new WriteExpectation($address, unpack('L', pack('f', $value))[1], 32);
        $this->expectations[] = $expectation;

        return $expectation;
    }

    protected function shouldReadFrom(string $name, int $value): ReadExpectation
    {
        $address = $this->addressOf($name);
        return $this->shouldRead($address, $value);
    }

    protected function shouldReadLongFrom(string $name, int $value): ReadExpectation
    {
        $address = $this->addressOf($name);
        return $this->shouldReadLong($address, $value);
    }

    protected function shouldReadWordFrom(string $name, int $value): ReadExpectation
    {
        $address = $this->addressOf($name);
        return $this->shouldReadWord($address, $value);
    }

    protected function shouldReadByteFrom(string $name, int $value): ReadExpectation
    {
        $address = $this->addressOf($name);
        return $this->shouldReadByte($address, $value);
    }

    protected function shouldWriteTo(string $name, int $value): WriteExpectation
    {
        return $this->shouldWriteLongTo($name, $value);
    }

    protected function shouldWriteLongTo(string $name, int $value): WriteExpectation
    {
        $address = $this->addressOf($name);
        return $this->shouldWriteLong($address, $value);
    }

    protected function shouldWriteWordTo(string $name, int $value): WriteExpectation
    {
        $address = $this->addressOf($name);
        return $this->shouldWriteWord($address, $value);
    }

    protected function shouldWriteByteTo(string $name, int $value): WriteExpectation
    {
        $address = $this->addressOf($name);
        return $this->shouldWriteByte($address, $value);
    }

    protected function shouldWriteStringTo(string $name, string $value): StringWriteExpectation
    {
        $address = $this->addressOf($name);
        return $this->shouldWriteString($address, $value);
    }

    protected function shouldReadSymbolOffset(string $name, int $offset, int $value): ReadExpectation
    {
        return $this->shouldReadLong($this->addressOf($name) + $offset, $value);
    }

    protected function shouldWriteSymbolOffset(string $name, int $offset, int $value): WriteExpectation
    {
        return $this->shouldWriteLong($this->addressOf($name) + $offset, $value);
    }

    protected function alloc(int $size): int
    {
        $cur = $this->currentAlloc;
        $this->currentAlloc += $size;

        if ($this->currentAlloc % 4 !== 0) {
            $this->currentAlloc += 4 - ($this->currentAlloc % 4);
        }

        return $cur;
    }

    public function call(string $name): CallCommand
    {
        if ($this->entry->symbol) {
            throw new \RuntimeException(
                "Cannot use new call() and old singleCall() commands in the same test"
            );
        }

        $command = new CallCommand($name);
        $this->expectations[] = $command;

        return $command;
    }

    public function singleCall(string $name): self
    {
        if (array_filter(
            $this->expectations,
            fn($e) => $e instanceof CallCommand)
        ) {
            throw new \RuntimeException(
                "Cannot use old singleCall() and new call() commands in the same test"
            );
        }

        $this->entry->symbol = $name;
        return $this;
    }

    protected function with(int|float|WildcardArgument ...$arguments): self
    {
        if (array_filter(
            $this->expectations,
            fn($e) => $e instanceof CallCommand)
        ) {
            throw new \RuntimeException(
                "Cannot use old with() and new call() commands in the same test"
            );
        }

        $this->entry->parameters = $arguments;
        return $this;
    }

    protected function singleShouldReturn(int|float $value): self
    {
        if (array_filter(
            $this->expectations,
            fn($e) => $e instanceof CallCommand)
        ) {
            throw new \RuntimeException(
                "Cannot use old singleShouldReturn() and new call() commands in the same test"
            );
        }

        if (is_float($value)) {
            $this->entry->floatReturn = $value;
        } else {
            $this->entry->return = $value;
        }

        return $this;
    }

    protected function shouldReturn(int|float $value): void
    {
        $this->expectations[] = new ReturnExpectation($value);
    }

    protected function forceStop(): void
    {
        $this->forceStop = true;
    }

    protected function doNotRandomizeMemory(): void
    {
        $this->randomizeMemory = false;
    }

    protected function rellocate(string $name, int $address): void
    {
        $this->testRelocations[] = new TestRelocation($name, $address);
    }

    protected function run(): void
    {
        // This was moved to the runner.
    }

    protected function initUint(int $address, int $value, int $size): void
    {
        $this->initializations[] = new MemoryInitialization($size, $address, $value);
    }

    protected function initUint8(int $address, int $value): void
    {
        $this->initUint($address, $value, 8);
    }

    protected function initUint16(int $address, int $value): void
    {
        $this->initUint($address, $value, 16);
    }

    protected function initUint32(int $address, int $value): void
    {
        $this->initUint($address, $value, 32);
    }

    public function setObjectFile(string $path): void
    {
        $this->objectFile = $path;
    }

    public function setParsedObject(ParsedObject $parsedObject): void
    {
        $this->parsedObject = $parsedObject;
    }

    protected function findTestRelocation(string $name): ?TestRelocation
    {
        $relocations = array_filter($this->testRelocations, fn($r) => $r->name === $name);

        return $relocations ? reset($relocations) : null;
    }

    /*
     * Allocates a symbol with the given size and returns its address.
     */
    public function setSize(string $name, int $size): int
    {
        if ($this->findTestRelocation($name)) {
            throw new \RuntimeException("Symbol $name already allocated");
        }

        if ($this->parsedObject->unit->findExportedSymbol($name)) {
            throw new \RuntimeException("Cannot allocate symbol $name, it is already defined in the object file");
        }

        $address = $this->alloc($size);
        $this->rellocate($name, $address);

        return $address;
    }

    /**
     * Returns the address of a symbol, allocating it if necessary.
     */
    public function addressOf(string $name): int
    {
        if ($relocation = $this->findTestRelocation($name)) {
            return $relocation->address;
        }

        if ($symbol = $this->parsedObject->unit->findExportedSymbol($name)) {
            return $symbol->linkedAddress;
        }

        $address = $this->alloc(4);
        $this->rellocate($name, $address);

        return $address;
    }

    /**
     * Allocates a string and returns its address.
     */
    protected function allocString(string $str): int
    {
        $address = $this->alloc(strlen($str) + 1);
        foreach (str_split($str) as $i => $char) {
            $this->initUint8($address + $i, ord($char));
        }
        $this->initUint8($address + strlen($str), 0);
        return $address;
    }
}
