<?php

declare(strict_types=1);

use Lhsazevedo\Sh4ObjTest\TestCase;

return new class extends TestCase {
    protected string $objectFile = __DIR__ . '/add.obj';

    public function testSimpleTest() {
        $this->call('_add')
            ->with(4, 2)
            ->shouldReturn(6)
            ->run();
    }
};
