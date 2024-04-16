<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Simulator\Types;

readonly class U4 extends UInt
{
    public const BIT_COUNT = 4;

    public const MAX_VALUE = 0xF;

    public const MIN_VALUE = 0x0;

    public const PACK_FORMAT = 'C';
}
