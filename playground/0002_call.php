<?php

declare(strict_types=1);

use Lhsazevedo\Objsim\TestCase;

return new class extends TestCase {
    protected string $objectFile = __DIR__ . '/0002_call.obj';

    public function testSimpleTest() {
        $this->expectCall('syGetInfo');

        $this->call('_myfunc')->run();
    }

    // public function testSimpleTest() {
    //     $this->expectRead('menustate')->offset(0x68)->as , 0);

    //     $this->expectReadAt(0x0100);

    //     $this->call('title')->with(0);
    // }
};
