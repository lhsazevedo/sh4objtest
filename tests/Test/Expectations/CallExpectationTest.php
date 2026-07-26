<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Tests\Test\Expectations;

use Lhsazevedo\Sh4ObjTest\Test\Expectations\CallExpectation;
use PHPUnit\Framework\TestCase;

class CallExpectationTest extends TestCase
{
    public function testVariadicSetsFixedCountAndIsFluent(): void
    {
        $expectation = new CallExpectation('sprintf', 0x1000);

        $this->assertNull($expectation->variadicFixed);

        $result = $expectation->variadic(2);

        $this->assertSame($expectation, $result);
        $this->assertSame(2, $expectation->variadicFixed);
    }
}
