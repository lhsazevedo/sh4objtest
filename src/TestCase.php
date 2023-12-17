<?php

declare(strict_types=1);

namespace Lhsazevedo\Objsim;

abstract class AbscractExpectation {}

class CallExpectation extends AbscractExpectation
{
    public function __construct(
        public string $name
    ) {}
}

class TestCase
{
    protected string $objectFile;

    private string $entry;

    /** @var Expectation[] */
    private $expectations = [];

    protected function expectCall($name)
    {
        $expectation = new CallExpectation($name);
        $this->expectations[] = $expectation;

        return $expectation;
    }

    protected function call($name)
    {
        $this->entry = $name;
        return $this;
    }

    protected function run()
    {
        $object = ObjectParser::parse($this->objectFile);

        $simulator = new Simulator($object, $this->expectations, $this->entry);
        $simulator->run();
    }
}
