<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Tests\Test;

use Lhsazevedo\Sh4ObjTest\BinaryReader;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\SectionHeader;
use Lhsazevedo\Sh4ObjTest\Parser\Chunks\UnitHeader;
use Lhsazevedo\Sh4ObjTest\Parser\ObjectData;
use Lhsazevedo\Sh4ObjTest\Parser\ParsedObject;
use Lhsazevedo\Sh4ObjTest\Test\Runner;
use PHPUnit\Framework\TestCase;

class RunnerTest extends TestCase
{
    public function testSectionsAreLaidOutByTheirLength(): void
    {
        $unit = self::unit();
        $unit->addSection(self::section('P', 8, "\2\2\2\2"));
        $unit->addSection(self::section('D', 4, "\1\1\1\1"));

        $image = Runner::linkObject(new ParsedObject($unit));

        $this->assertSame(8, $unit->sections[1]->linkedAddress);
        $this->assertSame("\2\2\2\2\0\0\0\0\1\1\1\1", $image);
    }

    public function testUninitialisedSectionGoesPastTheImage(): void
    {
        $unit = self::unit();
        $unit->addSection(self::section('B', 12));
        $unit->addSection(self::section('D', 4, "\1\1\1\1"));
        $unit->addSection(self::section('C', 4, "\2\2\2\2"));

        $image = Runner::linkObject(new ParsedObject($unit));

        $this->assertSame(0, $unit->sections[1]->linkedAddress);
        $this->assertSame(4, $unit->sections[2]->linkedAddress);
        $this->assertSame(8, $unit->sections[0]->linkedAddress);
        $this->assertSame("\1\1\1\1\2\2\2\2", $image);
    }

    public function testObjectPathIsTheOneTheSuiteWrote(): void
    {
        $dir = sys_get_temp_dir() . '/runner-test-' . uniqid();
        mkdir($dir);
        touch("$dir/a.php");
        file_put_contents("$dir/suite.php", '<?php return ["groups" => [["tests" => ["a.php"], "objects" => ["out/a.obj"]]]];');

        try {
            $items = Runner::collectWorkItems("$dir/suite.php", null);
        } finally {
            unlink("$dir/suite.php");
            unlink("$dir/a.php");
            rmdir($dir);
        }

        $this->assertSame("$dir/out/a.obj", $items[0]['objectFile']);
    }

    private static function unit(): UnitHeader
    {
        return new UnitHeader(new BinaryReader(
            "\x00\x00\x02\x00\x00\x00\x00"
            . "\x04unit\x04A_SH" . str_repeat('0', 12)
        ));
    }

    private static function section(string $name, int $length, ?string $bytes = null): SectionHeader
    {
        $section = new SectionHeader(new BinaryReader(
            "\x00" . pack('NNN', 0, $length, 4) . "\x00\x00\x00" . chr(strlen($name)) . $name
        ));
        if ($bytes !== null) {
            $section->addObjectData(new ObjectData(new BinaryReader(
                "\x80" . pack('N', 0) . chr(strlen($bytes)) . $bytes
            )));
        }

        return $section;
    }
}
