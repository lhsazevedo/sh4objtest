<?php

declare(strict_types=1);

use Lhsazevedo\Sh4ObjTest\TestCase;

return new class extends TestCase {
    protected ?string $objectFile = __DIR__ . '/main.obj';

    public function testSimpleTest() {
        $this->shouldCall('_func')->with(42);

        $this->call('_main')->run();
    }
};
