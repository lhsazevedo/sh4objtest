<?php

declare(strict_types=1);

use Lhsazevedo\Objsim\TestCase;

return new class extends TestCase {
    protected string $objectFile = __DIR__ . '/0002_call.obj';

    public function testSimpleTest() {
        $this->expectCall('syGetInfo');
        $this->expectCall('syGetInfo2');

        $this->call('_myfunc')->run();
    }
};
