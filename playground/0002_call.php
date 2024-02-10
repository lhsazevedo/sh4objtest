<?php

declare(strict_types=1);

use Lhsazevedo\Sh4ObjTest\TestCase;

return new class extends TestCase {
    protected ?string $objectFile = __DIR__ . '/0002_call.obj';

    public function testSimpleTest() {
        $this->shouldCall('_syGetInfo');

        $this->call('_myfunc')->run();
    }
};
