<?php

declare(strict_types=1);

use Lhsazevedo\Objsim\TestCase;

return new class extends TestCase {
    protected string $objectFile = __DIR__ . '/main.obj';

    public function testOffsetDataIs0() {
        $this->shouldWriteSymbolOffset('_global_struct', 0x18, 42);

        $this->call('_main')->shouldReturn(0)->run();
    }
};
