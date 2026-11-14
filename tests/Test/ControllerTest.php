<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Tests\Test;

use Lhsazevedo\Sh4ObjTest\Test\Controller;
use Lhsazevedo\Sh4ObjTest\Test\NullEventListener;
use PHPUnit\Framework\TestCase;

class ControllerTest extends TestCase
{
    public function testWorkersRunEveryItem(): void
    {
        $controller = new Controller(
            events: new NullEventListener(),
            binPath: __DIR__ . '/Fixtures/stub-worker.php',
            workerCount: 2,
            shouldTrackCoverage: false,
            failFast: false,
            callBlocklist: [],
        );

        $items = [];
        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            $items[] = ['testFile' => "$name.php", 'objectFile' => "$name.obj"];
        }

        $result = $controller->run($items);

        $this->assertTrue($result->success);
        $ran = array_column($result->files, 'testFile');
        sort($ran);
        $this->assertSame(['a.php', 'b.php', 'c.php', 'd.php', 'e.php'], $ran);
    }
}
