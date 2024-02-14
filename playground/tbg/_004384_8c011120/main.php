<?php

declare(strict_types=1);

use Lhsazevedo\Sh4ObjTest\TestCase;
use Lhsazevedo\Sh4ObjTest\Simulator\Arguments\WildcardArgument;

function fdec(float $value) {
    return unpack('L', pack('f', $value))[1];
}

return new class extends TestCase {
    public function test_nop_8c011120()
    {
        $this->call('_nop_8c011120')->run();
    }

    /// initDatQueue_8c011124 ///

    public function test_initDatQueue_8c011124()
    {
        $sizeOfQueuedDat = 0x10;
        $queueItems = 16;

        $this->shouldCall('_syMalloc')
            ->with($queueItems * $sizeOfQueuedDat)
            ->andReturn(0xbebacafe);

        $this->shouldWriteTo('_var_datQueue_8c157a8c', 0xbebacafe);
        $this->shouldWriteTo('_var_datQueueEnd_8c157a94', 0xbebacafe + $queueItems * $sizeOfQueuedDat);

        $this->call('_initDatQueue_8c011124')
            ->with($queueItems)
            ->shouldReturn(1)
            ->run();
    }

    public function test_initDatQueue_8c011124_returns0OnAllocError()
    {
        $sizeOfQueuedDat = 0x10;
        $queueItems = 16;

        $this->shouldCall('_syMalloc')
            ->with($queueItems * $sizeOfQueuedDat)
            ->andReturn(0);

        $this->shouldWriteTo('_var_datQueue_8c157a8c', 0);

        $this->call('_initDatQueue_8c011124')
            ->with($queueItems)
            ->shouldReturn(0)
            ->run();
    }

    public function test_initDatQueue_8c011124_clearQueueWhenNIs0()
    {
        $this->shouldWriteTo('_var_datQueueEnd_8c157a94', -1);
        $this->shouldWriteTo('_var_datQueue_8c157a8c', -1);

        $this->call('_initDatQueue_8c011124')
            ->with(0)
            ->shouldReturn(1)
            ->run();
    }

    /// FUN_8c01116a ///

    public function test_FUN_8c01116a()
    {
        $this->initUint32($this->addressOf('_var_datQueue_8c157a8c'), 0xbebacafe);

        $this->shouldWriteTo('_var_datQueueCurrent_8c157a90', 0xbebacafe);
        $this->shouldWriteTo('__8c157a80_basedir', 'DATA EMPTY');
        $this->shouldWriteTo('__8c157a98', 1);

        $this->call('_FUN_8c01116a')
            ->run();
    }
};
