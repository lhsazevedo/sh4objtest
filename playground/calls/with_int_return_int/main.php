<?php

declare(strict_types=1);

use Lhsazevedo\Objsim\TestCase;

return new class extends TestCase {
    protected string $objectFile = __DIR__ . '/main.obj';

    public function testSimpleTest() {
        $this->shouldCall('func')->with(42);

        $this->call('_main')->run();
    }
};
