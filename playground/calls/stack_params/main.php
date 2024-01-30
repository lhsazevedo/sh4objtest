<?php

declare(strict_types=1);

use Lhsazevedo\Sh4ObjTest\TestCase;

return new class extends TestCase {
    protected string $objectFile = __DIR__ . '/main.obj';

    public function testSimpleTest() {
        $this->shouldCall('func')->with(1, 2, 3, 4, 5, 6, 7, 8);

        $this->call('_main')->run();
    }
};
