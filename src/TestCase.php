<?php

declare(strict_types=1);

namespace Lhsazevedo\Objsim;

use Throwable;

abstract class AbscractExpectation {}

class CallExpectation extends AbscractExpectation
{
    public array $parameters = [];

    public ?int $return = null;

    public function __construct(
        public string $name
    ) {}

    public function with(...$parameters): self
    {
        $this->parameters = $parameters;
        return $this;
    }

    public function andReturn($value): self
    {
        $this->return = $value;
        return $this;
    }
}

class ReadExpectation extends AbscractExpectation
{
    public function __construct(
        public int $address,
        public int $value
    ) {}
}

class WriteExpectation extends AbscractExpectation
{
    public function __construct(
        public int $address,
        public int $value
    ) {}
}

class SymbolOffsetReadExpectation extends AbscractExpectation
{
    public function __construct(
        public string $name,
        public int $offset,
        public int $value
    ) {}
}

class SymbolOffsetWriteExpectation extends AbscractExpectation
{
    public function __construct(
        public string $name,
        public int $offset,
        public int $value
    ) {}
}

class Entry {
    public function __construct(
        public ?string $symbol = null,
        public array $parameters = [],
        // TODO: functions can return pointers
        public ?int $return = null,
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


class TestCase
{
    protected string $objectFile;

    protected ParsedObject $parsedObject;

    private Entry $entry;

    /** @var Expectation[] */
    private $expectations = [];

    private bool $forceStop = false;

    private int $currentAlloc = 1024 * 1024 * 8;

    /** @var TestRelocation[] */
    private array $testRelocations = [];

    public function __construct()
    {
        $this->entry = new Entry();
        $this->parsedObject = ObjectParser::parse($this->objectFile);
    }

    protected function shouldCall($name)
    {
        $expectation = new CallExpectation($name);
        $this->expectations[] = $expectation;

        return $expectation;
    }

    protected function shouldRead($address, $value)
    {
        $expectation = new ReadExpectation($address, $value);
        $this->expectations[] = $expectation;

        return $expectation;
    }

    protected function shouldWrite($address, $value)
    {
        $expectation = new WriteExpectation($address, $value);
        $this->expectations[] = $expectation;

        return $expectation;
    }

    protected function shouldReadSymbolOffset($name, $offset, $value)
    {
        $expectation = new SymbolOffsetReadExpectation($name, $offset, $value);
        $this->expectations[] = $expectation;

        return $expectation;
    }

    protected function shouldWriteSymbolOffset($name, $offset, $value)
    {
        $expectation = new SymbolOffsetWriteExpectation($name, $offset, $value);
        $this->expectations[] = $expectation;

        return $expectation;
    }

    protected function alloc(int $size): int
    {
        $cur = $this->currentAlloc;
        $this->currentAlloc += $size;

        return $cur;
    }

    protected function call($name): self
    {
        $this->entry->symbol = $name;
        return $this;
    }

    protected function with(...$parameters): self
    {
        $this->entry->parameters = $parameters;
        return $this;
    }

    protected function shouldReturn($value): self
    {
        $this->entry->return = $value;
        return $this;
    }

    protected function forceStop(): void
    {
        $this->forceStop = true;
    }

    protected function rellocate(string $name, int $address)
    {
        $this->testRelocations[] = new TestRelocation($name, $address);
    }

    protected function run(): void
    {
        $simulator = new Simulator(
            $this->parsedObject,
            $this->expectations,
            $this->entry,
            $this->forceStop,
            $this->testRelocations,
        );

        try {
            $simulator->run();
        } catch (Throwable $t) {
            echo $t->getMessage()  . "\n";
            $simulator->hexdump();
        }

        // Cleanup
        $this->forceStop = false;
        $this->entry = new Entry();
        $this->expectations = [];
        $this->testRelocations = [];
        $this->currentAlloc = 1024 * 1024 * 8;
    }
}
