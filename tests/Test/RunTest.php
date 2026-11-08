<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Tests\Test;

use Lhsazevedo\Sh4ObjTest\Simulator\SymbolTable;
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

    /**
     * @param int[] $code
     * @param array<int|float> $arguments
     */
    private function runProgram(array $code, array $arguments, int|float $return): void
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
                expectations: [
                    (new CallCommand('_f'))->with(...$arguments),
                    new ReturnExpectation($return),
                ],
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
