<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Tests\Test;

use Lhsazevedo\Sh4ObjTest\Simulator\Symbol;
use Lhsazevedo\Sh4ObjTest\Simulator\SymbolTable;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U32;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\AbstractExpectation;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\CallCommand;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\CallExpectation;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\ReturnExpectation;
use Lhsazevedo\Sh4ObjTest\Test\LinkedProgram;
use Lhsazevedo\Sh4ObjTest\Test\NullEventListener;
use Lhsazevedo\Sh4ObjTest\Test\Run;
use Lhsazevedo\Sh4ObjTest\Test\TestCaseDTO;
use PHPUnit\Framework\TestCase;

class RunTest extends TestCase
{
    public function testNegativeRegisterArgumentIsTwosComplement(): void
    {
        $this->expectNotToPerformAssertions();

        // MOV R4,R0; RTS; NOP
        $this->runProgram([0x6043, 0x000b, 0x0009], [-2], 0xfffffffe);
    }

    public function testNegativeStackArgumentIsTwosComplement(): void
    {
        $this->expectNotToPerformAssertions();

        // MOV.L @R15,R0; RTS; NOP
        $this->runProgram([0x60f2, 0x000b, 0x0009], [0, 0, 0, 0, -2], 0xfffffffe);
    }

    public function testFirstStackArgumentIsAtStackPointer(): void
    {
        $this->expectNotToPerformAssertions();

        // MOV.L @R15,R0; RTS; NOP
        $this->runProgram([0x60f2, 0x000b, 0x0009], [1, 2, 3, 4, 5, 6], 5);
    }

    public function testSecondStackArgumentIsAbove(): void
    {
        $this->expectNotToPerformAssertions();

        // MOV.L @(4,R15),R0; RTS; NOP
        $this->runProgram([0x50f1, 0x000b, 0x0009], [1, 2, 3, 4, 5, 6], 6);
    }

    public function testStackFloatArgumentIsWrittenAsBits(): void
    {
        $this->expectNotToPerformAssertions();

        // MOV.L @R15,R0; RTS; NOP
        $this->runExpectations(
            ['_f' => 0],
            [0x60f2, 0x000b, 0x0009],
            (new CallCommand('_f'))->with(2.5)->variadic(0),
            new ReturnExpectation(0x40200000),
        );
    }

    public function testUnmockedLocalCallReturnsToTheEntry(): void
    {
        $this->expectNotToPerformAssertions();

        $this->runProgram([
            0x4f22, // STS.L PR,@-R15
            0xb004, // BSR 0x0e
            0x0009, // NOP
            0x4f26, // LDS.L @R15+,PR
            0x000b, // RTS
            0x7001, // ADD #1,R0
            0x0009, // NOP
            0xe029, // 0x0e: MOV #41,R0
            0x000b, // RTS
            0x0009, // NOP
        ], [], 42);
    }

    public function testEachEntryCallStartsFromTheFirstArgumentRegister(): void
    {
        $this->expectNotToPerformAssertions();

        // MOV R4,R0; RTS; NOP
        $this->runExpectations(
            ['_f' => 0],
            [0x6043, 0x000b, 0x0009],
            (new CallCommand('_f'))->with(1, 2, 3, 4),
            new ReturnExpectation(1),
            (new CallCommand('_f'))->with(5),
            new ReturnExpectation(5),
        );
    }

    public function testEachEntryCallStartsAtItsEntry(): void
    {
        $this->expectNotToPerformAssertions();

        $this->runExpectations(
            ['_f' => 0, '_g' => 6],
            [
                0xe009, // _f: MOV #9,R0
                0x000b, // RTS
                0x0009, // NOP
                0x6043, // _g: MOV R4,R0
                0x000b, // RTS
                0x0009, // NOP
            ],
            (new CallCommand('_g'))->with(1),
            new ReturnExpectation(1),
            (new CallCommand('_g'))->with(5),
            new ReturnExpectation(5),
            new CallCommand('_f'),
            new ReturnExpectation(9),
        );
    }

    public function testTakenBtOntoAFunctionIsATailCall(): void
    {
        $this->expectNotToPerformAssertions();

        $this->runExpectations(
            ['_f' => 0, '_g' => 10],
            [
                0xe000, // MOV #0,R0
                0x2008, // TST R0,R0
                0x8901, // BT _g
                0x000b, // RTS
                0x0009, // NOP
                0xe005, // _g: MOV #5,R0
                0x000b, // RTS
                0x0009, // NOP
            ],
            new CallCommand('_f'),
            new CallExpectation('_g', 10),
        );
    }

    public function testTakenBfsOntoAFunctionIsATailCall(): void
    {
        $this->expectNotToPerformAssertions();

        $this->runExpectations(
            ['_f' => 0, '_g' => 12],
            [
                0xe001, // MOV #1,R0
                0x2008, // TST R0,R0
                0x8f02, // BF/S _g
                0x0009, // NOP
                0x000b, // RTS
                0x0009, // NOP
                0xe005, // _g: MOV #5,R0
                0x000b, // RTS
                0x0009, // NOP
            ],
            new CallCommand('_f'),
            new CallExpectation('_g', 12),
        );
    }

    public function testUntakenBtOntoAFunctionFallsThrough(): void
    {
        $this->expectNotToPerformAssertions();

        $this->runExpectations(
            ['_f' => 0, '_g' => 10],
            [
                0xe001, // MOV #1,R0
                0x2008, // TST R0,R0
                0x8901, // BT _g
                0x000b, // RTS
                0x0009, // NOP
                0xe005, // _g: MOV #5,R0
                0x000b, // RTS
                0x0009, // NOP
            ],
            new CallCommand('_f'),
            new ReturnExpectation(1),
        );
    }

    public function testVariadicEntryPutsTheRestOnTheStack(): void
    {
        $this->expectNotToPerformAssertions();

        // MOV.L @R15,R0; ADD R4,R0; RTS; NOP
        $this->runExpectations(
            ['_f' => 0],
            [0x60f2, 0x304c, 0x000b, 0x0009],
            (new CallCommand('_f'))->with(10, 20)->variadic(1),
            new ReturnExpectation(30),
        );
    }

    /**
     * @param int[] $code
     * @param array<int|float> $arguments
     */
    private function runProgram(array $code, array $arguments, int|float $return): void
    {
        $this->runExpectations(
            ['_f' => 0],
            $code,
            (new CallCommand('_f'))->with(...$arguments),
            new ReturnExpectation($return),
        );
    }

    /**
     * @param array<string, int> $entries
     * @param int[] $code
     */
    private function runExpectations(array $entries, array $code, AbstractExpectation ...$expectations): void
    {
        $symbols = new SymbolTable();
        foreach ($entries as $name => $address) {
            $symbols->addSymbol(new Symbol($name, U32::of($address), callable: true));
        }

        $program = new LinkedProgram(
            pack('v*', ...$code),
            $symbols,
            [],
            $entries,
        );

        (new Run(
            new NullEventListener(),
            new TestCaseDTO(
                name: 'test',
                objectFile: '',
                linkedProgram: $program,
                initializations: [],
                testRelocations: [],
                expectations: $expectations,
                defaultCallbacks: [],
                defaultConventions: [],
                shouldRandomizeMemory: false,
                sr: 0,
                shouldStopWhenFulfilled: false,
            ),
            false,
        ))->run();
    }
}
