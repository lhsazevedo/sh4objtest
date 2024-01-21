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

class TestCase
{
    protected string $objectFile;

    private Entry $entry;

    /** @var Expectation[] */
    private $expectations = [];

    private $currentAlloc = 1024 * 1024 * 8;

    public function __construct()
    {
        $this->entry = new Entry();
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

    protected function run()
    {
        $object = ObjectParser::parse($this->objectFile);

        $simulator = new Simulator($object, $this->expectations, $this->entry);

        try {
            $simulator->run();
        } catch (Throwable $t) {
            echo $t->getMessage()  . "\n";
            $simulator->hexdump();
        }
    }
}
