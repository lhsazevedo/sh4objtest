<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Test;

use Lhsazevedo\Sh4ObjTest\Simulator\Arguments\WildcardArgument;
use Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions\ArgumentType;
use Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions\CallingConvention;
use Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions\StackOffset;
use Lhsazevedo\Sh4ObjTest\Simulator\Exceptions\ExpectationException;
use Lhsazevedo\Sh4ObjTest\Simulator\Simulator;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\FloatingPointRegister;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\GeneralRegister;

class ArgumentVerifier
{
    public function verify(
        Simulator $simulator,
        CallingConvention $convention,
        mixed $expected,
        string $readableName,
    ): void
    {
        match (true) {
            $expected instanceof WildcardArgument => $this->verifyWildcard($convention),
            is_int($expected) => $this->verifyInt($simulator, $convention, $expected, $readableName),
            is_float($expected) => $this->verifyFloat($simulator, $convention, $expected, $readableName),
            is_string($expected) => $this->verifyString($simulator, $convention, $expected, $readableName),
            default => throw new \Exception("Unexpected argument type", 1),
        };
    }

    private function verifyWildcard(CallingConvention $convention): void
    {
        // FIXME: Allow wildcard float arguments?
        $convention->takeArgumentStorage(ArgumentType::General);
    }

    private function verifyInt(Simulator $simulator, CallingConvention $convention, int $expected, string $readableName): void
    {
        $storage = $convention->takeArgumentStorage(ArgumentType::General);
        $expected &= 0xffffffff;

        if ($storage instanceof GeneralRegister) {
            $register = $storage->index();
            $storage = "r$register";

            $actual = $simulator->getRegister($register);
        } else if ($storage instanceof StackOffset) {
            $offset = $storage->offset;
            $address = $simulator->getRegister(15)->value + $offset;
            $storage = "stack offset $offset ($address)";

            $actual = $simulator->getMemory()->readUInt32($address);
        } else {
            throw new \Exception("Unexpected argument storage type", 1);
        }

        if ($actual->equals($expected)) {
            return;
        }

        $actualHex = dechex($actual->value);
        $expectedHex = dechex($expected);
        throw new ExpectationException("Unexpected argument for $readableName in $storage. Expected $expected (0x$expectedHex), got $actual (0x$actualHex)");
    }

    private function verifyFloat(Simulator $simulator, CallingConvention $convention, float $expected, string $readableName): void
    {
        $storage = $convention->takeArgumentStorage(ArgumentType::FloatingPoint);
        $expectedDecRepresentation = unpack('L', pack('f', $expected))[1];

        if ($storage instanceof FloatingPointRegister) {
            $register = $storage->index();
            $storage = "fr$register";

            $actual = $simulator->getFloatRegister($register);
            $actualDecRepresentation = unpack('L', pack('f', $actual))[1];
        } else if ($storage instanceof StackOffset) {
            $offset = $storage->offset;
            $address = $simulator->getRegister(15)->value + $offset;
            $storage = "stack offset $offset ($address)";

            $actualDecRepresentation = $simulator->getMemory()->readUInt32($address);
            $actual = unpack('f', pack('L', $actualDecRepresentation))[1];
        } else {
            throw new \Exception("Unexpected argument storage type", 1);
        }

        if ($actualDecRepresentation === $expectedDecRepresentation) {
            return;
        }

        throw new ExpectationException("Unexpected float argument for $readableName in $storage. Expected $expected, got $actual");
    }

    private function verifyString(Simulator $simulator, CallingConvention $convention, string $expected, string $readableName): void
    {
        $storage = $convention->takeArgumentStorage(ArgumentType::General);

        if ($storage instanceof GeneralRegister) {
            $register = $storage->index();
            $storage = "r$register";

            $address = $simulator->getRegister($register);
            $actual = $simulator->getMemory()->readString($address->value);
        } else if ($storage instanceof StackOffset) {
            $offset = $storage->offset;
            $stackAddress = $simulator->getRegister(15)->value + $offset;
            $storage = "stack offset $offset ($stackAddress)";

            $address = $simulator->getMemory()->readUInt32($stackAddress);
            $actual = $simulator->getMemory()->readString($address->value);
        } else {
            throw new \Exception("Unexpected argument storage type", 1);
        }

        if ($actual === $expected) {
            return;
        }

        $actualHex = bin2hex($actual);
        $expectedHex = bin2hex($expected);
        throw new ExpectationException("Unexpected char* argument for $readableName in $storage. Expected $expected (0x$expectedHex), got $actual (0x$actualHex)");
    }
}
