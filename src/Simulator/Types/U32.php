<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Simulator\Types;

readonly class U32 extends UInt
{
    public const BIT_COUNT = 32;

    public const MAX_VALUE = 0xFFFFFFFF;

    public const MIN_VALUE = 0x00000000;

    public const PACK_FORMAT = 'V';
}
