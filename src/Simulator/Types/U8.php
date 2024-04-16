<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Simulator\Types;

readonly class U8 extends UInt
{
    public const BIT_COUNT = 8;

    public const MAX_VALUE = 0xFF;

    public const MIN_VALUE = 0x00;

    public const PACK_FORMAT = 'C';
}
