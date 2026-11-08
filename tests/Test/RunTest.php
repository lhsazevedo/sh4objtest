<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Tests\Test;

use Lhsazevedo\Sh4ObjTest\Simulator\SymbolTable;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\AbstractExpectation;
use Lhsazevedo\Sh4ObjTest\Test\Expectations\CallCommand;
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
            [0x60f2, 0x000b, 0x0009],
            (new CallCommand('_f'))->with(2.5)->variadic(0),
            new ReturnExpectation(0x40200000),
        );
    }

    public function testEachEntryCallStartsFromTheFirstArgumentRegister(): void
    {
        $this->expectNotToPerformAssertions();

        // MOV R4,R0; RTS; NOP
        $this->runExpectations(
            [0x6043, 0x000b, 0x0009],
            (new CallCommand('_f'))->with(1, 2, 3, 4),
            new ReturnExpectation(1),
            (new CallCommand('_f'))->with(5),
            new ReturnExpectation(5),
        );
    }

    public function testVariadicEntryPutsTheRestOnTheStack(): void
    {
        $this->expectNotToPerformAssertions();

        // MOV.L @R15,R0; ADD R4,R0; RTS; NOP
        $this->runExpectations(
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
            $code,
            (new CallCommand('_f'))->with(...$arguments),
            new ReturnExpectation($return),
        );
    }

    /**
     * @param int[] $code
     */
    private function runExpectations(array $code, AbstractExpectation ...$expectations): void
    {
        $program = new LinkedProgram(
            pack('v*', ...$code),
            new SymbolTable(),
            [],
            ['_f' => 0],
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
