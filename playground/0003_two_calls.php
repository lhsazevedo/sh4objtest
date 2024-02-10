<?php

declare(strict_types=1);

use Lhsazevedo\Sh4ObjTest\TestCase;

return new class extends TestCase {
    protected ?string $objectFile = __DIR__ . '/0003_two_calls.obj';

    public function testSimpleTest() {
        $this->shouldCall('_syGetInfo');
        $this->shouldCall('_syGetInfo2');

        $this->call('_myfunc')->run();
    }
};
