<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Tests\Test;

use Lhsazevedo\Sh4ObjTest\Simulator\BinaryMemory;
use Lhsazevedo\Sh4ObjTest\Simulator\Exceptions\ExpectationException;
use Lhsazevedo\Sh4ObjTest\Simulator\Simulator;
use Lhsazevedo\Sh4ObjTest\Simulator\Symbol;
use Lhsazevedo\Sh4ObjTest\Simulator\SymbolTable;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations\BranchOperation;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations\ReadOperation;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations\StoreQueueFlushOperation;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations\WriteOperation;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U32;
use Lhsazevedo\Sh4ObjTest\Test\ArgumentVerifier;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\CallExpectation;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\ReadExpectation;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\StoreQueueFlushExpectation;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\StringWriteExpectation;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\WriteExpectation;
use Lhsazevedo\Sh4ObjTest\Test\ExpectationMatcher;
use PHPUnit\Framework\TestCase;

class ExpectationMatcherTest extends TestCase
{
    private function simulator(): Simulator
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));
        $simulator->setRegister(15, U32::of(512));

        return $simulator;
    }

    /** @param \Lhsazevedo\Sh4ObjTest\Test\Expectations\AbstractExpectation[] $expectations */
    private function matcher(
        array $expectations,
        ?SymbolTable $symbols = null,
    ): ExpectationMatcher
    {
        return new ExpectationMatcher(
            $expectations,
            $symbols ?? new SymbolTable(),
            testRelocations: [],
            defaultCallbacks: [],
            defaultConventions: [],
            unresolvedRelocations: [],
            argumentVerifier: new ArgumentVerifier(),
            onFulfilled: fn (string $m) => null,
            onInfo: fn (string $m) => null,
        );
    }

    public function testOnWriteFulfillsMatchingExpectationAndShifts(): void
    {
        $simulator = $this->simulator();
        $matcher = $this->matcher([new WriteExpectation(100, 42, 32)]);

        $matcher->matchWrite($simulator, new WriteOperation(0, 0, U32::of(100), U32::of(42)));

        $this->assertTrue($matcher->isEmpty());
    }

    public function testOnWriteThrowsOnMismatchedValue(): void
    {
        $simulator = $this->simulator();
        $matcher = $this->matcher([new WriteExpectation(100, 42, 32)]);

        $this->expectException(ExpectationException::class);
        $matcher->matchWrite($simulator, new WriteOperation(0, 0, U32::of(100), U32::of(1)));
    }

    public function testOnWriteThrowsOnUnexpectedNonStackWrite(): void
    {
        $simulator = $this->simulator();
        $matcher = $this->matcher([]);

        $this->expectException(ExpectationException::class);
        $matcher->matchWrite($simulator, new WriteOperation(0, 0, U32::of(100), U32::of(1)));
    }

    public function testOnWriteAllowsUnexpectedStackWrite(): void
    {
        $simulator = $this->simulator();
        $matcher = $this->matcher([]);

        // Address 512 == SP, so it's a stack write and is silently allowed.
        $matcher->matchWrite($simulator, new WriteOperation(0, 0, U32::of(512), U32::of(1)));

        $this->assertTrue($matcher->isEmpty());
    }

    public function testOnWriteFulfillsMatchingStringExpectation(): void
    {
        $simulator = $this->simulator();
        $simulator->getMemory()->writeBytes(200, "hi\0");
        $matcher = $this->matcher([new StringWriteExpectation(100, 'hi')]);

        $matcher->matchWrite($simulator, new WriteOperation(0, 0, U32::of(100), U32::of(200)));

        $this->assertTrue($matcher->isEmpty());
    }

    public function testOnStoreQueueFlushFulfillsMatchingExpectationAndShifts(): void
    {
        $simulator = $this->simulator();
        $matcher = $this->matcher([new StoreQueueFlushExpectation(0xe0001000)]);

        $matcher->matchStoreQueueFlush($simulator, new StoreQueueFlushOperation(0, 0, U32::of(0xe0001000)));

        $this->assertTrue($matcher->isEmpty());
    }

    public function testOnStoreQueueFlushThrowsOnMismatchedAddress(): void
    {
        $simulator = $this->simulator();
        $matcher = $this->matcher([new StoreQueueFlushExpectation(0xe0001000)]);

        $this->expectException(ExpectationException::class);
        $matcher->matchStoreQueueFlush($simulator, new StoreQueueFlushOperation(0, 0, U32::of(0xe0002000)));
    }

    public function testOnStoreQueueFlushThrowsWhenUnexpected(): void
    {
        $simulator = $this->simulator();
        $matcher = $this->matcher([]);

        $this->expectException(ExpectationException::class);
        $matcher->matchStoreQueueFlush($simulator, new StoreQueueFlushOperation(0, 0, U32::of(0xe0001000)));
    }

    public function testOnReadFulfillsMatchingExpectationAndShifts(): void
    {
        $simulator = $this->simulator();
        $matcher = $this->matcher([new ReadExpectation(100, 42, 32)]);

        $matcher->matchRead($simulator, new ReadOperation(0, 0, U32::of(100), U32::of(42)));

        $this->assertTrue($matcher->isEmpty());
    }

    public function testOnReadThrowsOnMismatchedValueAtExpectedAddress(): void
    {
        $simulator = $this->simulator();
        $matcher = $this->matcher([new ReadExpectation(100, 42, 32)]);

        $this->expectException(ExpectationException::class);
        $matcher->matchRead($simulator, new ReadOperation(0, 0, U32::of(100), U32::of(1)));
    }

    public function testOnReadIgnoresUnrelatedRead(): void
    {
        $simulator = $this->simulator();
        $matcher = $this->matcher([new ReadExpectation(100, 42, 32)]);

        $matcher->matchRead($simulator, new ReadOperation(0, 0, U32::of(999), U32::of(1)));

        $this->assertFalse($matcher->isEmpty());
    }

    public function testOnBranchFulfillsCallToKnownSymbolAndDoesNotStop(): void
    {
        $symbols = new SymbolTable();
        $symbols->addSymbol(new Symbol('sprintf', U32::of(0x1000), callable: true));

        $simulator = $this->simulator();
        $matcher = $this->matcher([new CallExpectation('sprintf', 0x1000)], $symbols);

        // BSR opcode (0xB000) marks a call.
        $shouldStop = $matcher->matchBranch($simulator, new BranchOperation(0, 0xB000, U32::of(0x1000)));

        $this->assertFalse($shouldStop);
        $this->assertTrue($matcher->isEmpty());
    }

    public function testOnBranchThrowsOnUnexpectedCall(): void
    {
        $symbols = new SymbolTable();
        $symbols->addSymbol(new Symbol('sprintf', U32::of(0x1000), callable: true));

        $simulator = $this->simulator();
        $matcher = $this->matcher([new CallExpectation('memcpy', 0x2000)], $symbols);

        $this->expectException(ExpectationException::class);
        $matcher->matchBranch($simulator, new BranchOperation(0, 0xB000, U32::of(0x1000)));
    }

    public function testOnBranchStopsWhenJumpingToSymbolInsteadOfCalling(): void
    {
        $symbols = new SymbolTable();
        $symbols->addSymbol(new Symbol('sprintf', U32::of(0x1000), callable: true));

        $simulator = $this->simulator();
        $matcher = $this->matcher([new CallExpectation('sprintf', 0x1000)], $symbols);

        // BRA opcode (0xA000): a jump, not a call.
        $shouldStop = $matcher->matchBranch($simulator, new BranchOperation(0, 0xA000, U32::of(0x1000)));

        $this->assertTrue($shouldStop);
    }

    public function testOnBranchIgnoresUnrelatedBranch(): void
    {
        $simulator = $this->simulator();
        $matcher = $this->matcher([]);

        $shouldStop = $matcher->matchBranch($simulator, new BranchOperation(0, 0xB000, U32::of(0x1000)));

        $this->assertFalse($shouldStop);
    }
}
