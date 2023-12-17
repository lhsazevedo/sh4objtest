<?php

declare(strict_types=1);

require "TestCase.php";

class MyTest extends TestCase {
    protected string $objectFile = '0001_nop.obj';

    public function testSimpleTest() {
        // $this->expectCall('syGetInfo');

        $this->call('_myfunc')->run();
    }

    // public function testSimpleTest() {
    //     $this->expectRead('menustate')->offset(0x68)->as , 0);

    //     $this->expectReadAt(0x0100);

    //     $this->call('title')->with(0);
    // }
}
