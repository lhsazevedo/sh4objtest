<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Simulator\CallingConventions;

use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\FloatingPointRegister;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\GeneralRegister;

interface CallingConvention
{
    public function getNextArgumentStorage(ArgumentType $type): GeneralRegister|FloatingPointRegister|StackOffset;

    public function getNextArgumentStorageForValue(mixed $value): GeneralRegister|FloatingPointRegister|StackOffset;
}
