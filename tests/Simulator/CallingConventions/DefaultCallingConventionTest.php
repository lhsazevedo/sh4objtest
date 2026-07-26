<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Tests\Simulator\CallingConventions;

use Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions\ArgumentType;
use Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions\DefaultCallingConvention;
use Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions\StackOffset;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\FloatingPointRegister;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\GeneralRegister;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DefaultCallingConventionTest extends TestCase
{
    /**
     * @param array<array{ArgumentType, GeneralRegister|FloatingPointRegister|int}> $steps Each step is
     * [type, expected], where expected is a register, or a stack offset (int) for a StackOffset.
     */
    #[DataProvider('argumentSequenceProvider')]
    public function testArgumentSequence(?int $variadicFixed, array $steps): void
    {
        $convention = new DefaultCallingConvention();
        if ($variadicFixed !== null) {
            $convention->variadic($variadicFixed);
        }

        foreach ($steps as [$type, $expected]) {
            $storage = $convention->takeArgumentStorage($type);

            if (is_int($expected)) {
                $this->assertInstanceOf(StackOffset::class, $storage);
                $this->assertSame($expected, $storage->offset);
            } else {
                $this->assertSame($expected, $storage);
            }
        }
    }

    /** @return array<string, array{?int, array<array{ArgumentType, GeneralRegister|FloatingPointRegister|int}>}> */
    public static function argumentSequenceProvider(): array
    {
        return [
            'non-variadic fills all general registers before stack' => [
                null,
                [
                    [ArgumentType::General, GeneralRegister::R4],
                    [ArgumentType::General, GeneralRegister::R5],
                    [ArgumentType::General, GeneralRegister::R6],
                    [ArgumentType::General, GeneralRegister::R7],
                    [ArgumentType::General, 0],
                ],
            ],
            'variadic arguments go on stack even with free registers' => [
                // e.g. sprintf(buf, fmt, ...): 2 fixed args, rest variadic. R6/R7
                // are still free, but variadic args must land on the stack per
                // the SHC ABI.
                2,
                [
                    [ArgumentType::General, GeneralRegister::R4],
                    [ArgumentType::General, GeneralRegister::R5],
                    [ArgumentType::General, 0],
                    [ArgumentType::General, 4],
                ],
            ],
            'variadic cutoff counts across general and float arguments' => [
                // Fixed args can mix int/float; the cutoff is a total argument
                // position, not per-register-pool.
                2,
                [
                    [ArgumentType::General, GeneralRegister::R4],
                    [ArgumentType::FloatingPoint, FloatingPointRegister::FR4],
                    [ArgumentType::FloatingPoint, 0],
                ],
            ],
            'zero fixed arguments puts everything on stack' => [
                0,
                [
                    [ArgumentType::General, 0],
                ],
            ],
        ];
    }
}
