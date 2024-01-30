<?php

declare(strict_types=1);

use Lhsazevedo\Sh4ObjTest\TestCase;

return new class extends TestCase {
    protected string $objectFile = __DIR__ . '/main.obj';

    public function testSimpleTest() {

        // four == 4
        $this->shouldCall('_check')->with(1);

        // four == 3
        $this->shouldCall('_check')->with(0);

        // four == 5
        $this->shouldCall('_check')->with(0);


        // four != 4
        $this->shouldCall('_check')->with(0);

        // four != 3
        $this->shouldCall('_check')->with(1);

        // four != 5
        $this->shouldCall('_check')->with(1);


        // four > 4
        $this->shouldCall('_check')->with(0);

        // four > 3
        $this->shouldCall('_check')->with(1);

        // four > 5
        $this->shouldCall('_check')->with(0);


        // four < 4
        $this->shouldCall('_check')->with(0);

        // four < 3
        $this->shouldCall('_check')->with(0);

        // four < 5
        $this->shouldCall('_check')->with(1);


        // four >= 4
        $this->shouldCall('_check')->with(1);

        // four >= 3
        $this->shouldCall('_check')->with(1);

        // four >= 5
        $this->shouldCall('_check')->with(0);


        // four <= 4
        $this->shouldCall('_check')->with(1);

        // four <= 3
        $this->shouldCall('_check')->with(0);

        // four <= 5
        $this->shouldCall('_check')->with(1);

        $this->call('_main')
            ->with(4, 2)
            ->run();
    }
};
