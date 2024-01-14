<?php

declare(strict_types=1);

use Lhsazevedo\Objsim\TestCase;

return new class extends TestCase {
    protected string $objectFile = __DIR__ . '/main.obj';

    public function testOffsetDataIs0() {
        // $this->shouldCall('func')->with(42);
        // $this->initGlobal('_global_struct', [
        //     0, 0, 0, 0, // 0x00
        //     0, 0, 0, 0, // 0x04
        //     0, 0, 0, 0, // 0x08
        //     0, 0, 0, 0, // 0x0c
        //     0, 0, 0, 0, // 0x10
        //     0, 0, 0, 0, // 0x14
        //     45, 44, 43, 42, // 0x18
        // ]);

        $this->shouldReadOffset('_global_struct', 0x18, 0);

        $this->call('_main')->shouldReturn(0)->run();
    }
};
