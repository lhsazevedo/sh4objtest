<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

use Lhsazevedo\Sh4ObjTest\Simulator\Exceptions\ExpectationException;
use Lhsazevedo\Sh4ObjTest\Simulator\Simulator;

class ReturnValueVerifier
{
    /**
     * Verifies R0 (int) or FR0 (float) against $expected, and returns a
     * human-readable "Returned ..." message for the fulfilled expectation.
     */
    public function verify(Simulator $simulator, int|float $expected): string
    {
        if (is_int($expected)) {
            $actual = $simulator->getRegister(0);

            if (!$actual->equals($expected)) {
                throw new ExpectationException("Unexpected return value $actual, expecting $expected");
            }

            return "Returned $expected";
        }

        $actual = $simulator->getFloatRegister(0);
        $expectedDecRepresentation = unpack('L', pack('f', $expected))[1];
        $actualDecRepresentation = unpack('L', pack('f', $actual))[1];

        if ($actualDecRepresentation !== $expectedDecRepresentation) {
            throw new ExpectationException("Unexpected return value $actual, expecting $expected");
        }

        return "Returned float $expected";
    }
}
