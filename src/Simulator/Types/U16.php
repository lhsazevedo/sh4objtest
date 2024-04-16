<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Simulator\Types;

readonly class U16 extends UInt
{
    public const BIT_COUNT = 16;

    public const MAX_VALUE = 0xFFFF;

    public const MIN_VALUE = 0x0000;

    public const PACK_FORMAT = 'v';
}
