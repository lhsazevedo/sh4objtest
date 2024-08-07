<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest;

use Lhsazevedo\Sh4ObjTest\Simulator\Arguments\LocalArgument;
use Lhsazevedo\Sh4ObjTest\Simulator\Arguments\WildcardArgument;
use Lhsazevedo\Sh4ObjTest\Simulator\Exceptions\ExpectationException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

abstract class AbstractExpectation {}

class CallExpectation extends AbstractExpectation
{
    /** @var array<int|float|string|WildcardArgument|LocalArgument> */
    public array $parameters = [];

    public ?int $return = null;

    public ?\Closure $callback = null;

    public function __construct(
        public ?string $name,
        public int $address,
    ) {}

    public function with(int|float|string|WildcardArgument|LocalArgument ...$parameters): self
    {
        $this->parameters = $parameters;
        return $this;
    }

    public function andReturn(int|float $value): self
    {
        $this->return = $value;
        return $this;
    }

    public function do(\Closure $callback): self
    {
        $this->callback = $callback;
        return $this;
    }
}

class ReadExpectation extends AbstractExpectation
{
    public function __construct(
        public int $address,
        public int $value,
        public int $size,
    ) {
        /* TODO: Move this to value object? */
        if ($value < -(2 ** $size - 1)) {
            throw new \RuntimeException("Value $value is too small for $size bits");
        } elseif ($value >= 2 ** $size) {
            throw new \RuntimeException("Value $value is too big for $size bits");
        }

        $this->value &= (2**$size) - 1;
    }
}

class WriteExpectation extends AbstractExpectation
{
    public function __construct(
        public int $address,
        public int $value,
        public int $size,
    ) {
        /* TODO: Move this to value object? */
        if ($value < -(2**$size)) {
            throw new \RuntimeException("Value $value is too small for $size bits");
        } elseif ($value >= 2**$size) {
            throw new \RuntimeException("Value $value is too big for $size bits");
        }

        $this->value &= (2**$size) - 1;
    }
}

class StringWriteExpectation extends AbstractExpectation
{
    public function __construct(
        public int $address,
        public string $value,
    ) {}
}

class Entry {
    public function __construct(
        public ?string $symbol = null,

        /** @var int[]|float[] */
        public array $parameters = [],

        // TODO: functions can return pointers
        public ?int $return = null,

        public ?float $floatReturn = null,
    ) {}
}

class TestRelocation
{
    public function __construct(
        public string $name,
        public int $address,
    )
    {}
}

readonly class MemoryInitialization
{
    public function __construct(
        public int $size,
        public int $address,
        public int $value
    )
    {}
}

class TestCase
{
    protected ?string $objectFile = null;

    protected ParsedObject $parsedObject;

    private Entry $entry;

    /** @var AbstractExpectation[] */
    private $expectations = [];

    private bool $forceStop = false;

    private bool $randomizeMemory = true;

    private int $currentAlloc = 1024 * 1024 * 8;

    /** @var TestRelocation[] */
    private array $testRelocations = [];

    /** @var MemoryInitialization[] */
    private array $initializations = [];

    private bool $disasm = false;

    private string $linkedCode;

    private InputInterface $input;
    
    private OutputInterface $output;

    public function __construct()
    {
        $this->entry = new Entry();
    }

    public function _inject(
        InputInterface $input,
        OutputInterface $output,
    ) {
        $this->input = $input;
        $this->output = $output;
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

    protected function call(string $name): self
    {
        $this->entry->symbol = $name;
        return $this;
    }

    protected function with(int|float|WildcardArgument ...$arguments): self
    {
        $this->entry->parameters = $arguments;
        return $this;
    }

    protected function shouldReturn(int|float $value): self
    {
        if (is_float($value)) {
            $this->entry->floatReturn = $value;
        } else {
            $this->entry->return = $value;
        }

        return $this;
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
        $simulator = new Simulator(
            $this->input,
            $this->output,
            $this->parsedObject,
            $this->expectations,
            $this->entry,
            $this->forceStop,
            $this->randomizeMemory,
            $this->testRelocations,
            $this->initializations,
            $this->linkedCode,
        );

        if ($this->disasm) {
            $simulator->enableDisasm();
        }

        $simulator->run();

        // Cleanup
        // TODO: Better to instance the TestCase every time
        $this->forceStop = false;
        $this->randomizeMemory = true;
        $this->entry = new Entry();
        $this->expectations = [];
        $this->testRelocations = [];
        $this->currentAlloc = 1024 * 1024 * 8;
        $this->initializations = [];
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

    public function parseObject(): void
    {
        $this->parsedObject = ObjectParser::parse($this->objectFile);

        $linkedCode = '';
        // TODO: Handle multiple units?
        foreach ($this->parsedObject->unit->sections as $section) {
            // Align
            $remainder = strlen($linkedCode) % $section->alignment;
            if ($remainder) {
                $linkedCode .= str_repeat("\0", $section->alignment - $remainder);
            }

            $section->rellocate(strlen($linkedCode));

            $linkedCode .= $section->assembleObjectData();
        }

        $this->linkedCode = $linkedCode;
    }

    public function enableDisasm(): void
    {
        $this->disasm = true;
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
