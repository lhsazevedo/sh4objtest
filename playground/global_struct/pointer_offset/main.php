<?php

declare(strict_types=1);

use Lhsazevedo\Sh4ObjTest\TestCase;

return new class extends TestCase {
    protected string $objectFile = __DIR__ . '/main.obj';

    public function testOffsetDataIs0() {
        $ptr = $this->alloc(0x20);
        $this->shouldReadSymbolOffset('_global_struct_ptr', 0, $ptr);
        $this->shouldRead($ptr + 0x18, 42);

        $this->call('_main')->shouldReturn(1)->run();
    }

    // TODO: Multiple tests are not supported yet
    // public function testValueIsWrong() {
    //     $ptr = $this->alloc(0x20);
    //     $this->shouldReadSymbolOffset('_global_struct_ptr', 0, $ptr);
    //     $this->shouldRead($ptr + 0x18, 41);

    //     $this->call('_main')->shouldReturn(0)->run();
    // }
};
