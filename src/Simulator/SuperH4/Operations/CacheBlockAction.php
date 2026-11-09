<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations;

enum CacheBlockAction: string
{
    case Invalidate = 'OCBI';
    case WriteBack = 'OCBWB';
}
