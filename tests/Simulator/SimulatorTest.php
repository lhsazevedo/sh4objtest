<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Tests\Simulator;

use Lhsazevedo\Sh4ObjTest\Simulator\BinaryMemory;
use Lhsazevedo\Sh4ObjTest\Simulator\Simulator;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations\GenericOperation;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations\ReadOperation;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations\StoreQueueFlushOperation;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations\CacheBlockAction;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations\CacheBlockOperation;
use Lhsazevedo\Sh4ObjTest\Simulator\SuperH4\Operations\WriteOperation;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U16;
use Lhsazevedo\Sh4ObjTest\Simulator\Types\U32;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SimulatorTest extends TestCase
{
    public function testGeneralRegistersStartRandomized(): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));

        $allZero = true;
        for ($i = 0; $i < 15; $i++) {
            if ($simulator->getRegister($i)->value !== 0) {
                $allZero = false;
                break;
            }
        }
        $this->assertFalse($allZero, 'Registers R0-R14 should not all start at zero');
    }

    public function testFloatRegistersStartRandomized(): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));

        $bits = array_map(
            fn ($i) => unpack('L', pack('f', $simulator->getFloatRegister($i)))[1],
            range(0, 15),
        );

        $this->assertNotSame(array_fill(0, 16, 0), $bits, 'FR0-FR15 should not all start at zero');
    }

    public function testStackPointerStartsAtTopOfMemory(): void
    {
        $simulator = new Simulator(new BinaryMemory(1024 * 1024 * 16, randomize: false));

        $this->assertSame(1024 * 1024 * 16 - 4, $simulator->getRegister(15)->value);
    }

    #[DataProvider('shldProvider')]
    public function testShld(int $value, int $shiftAmount, int $expected): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));

        // SHLD R4,R5 (0100nnnnmmmm1101, n=5, m=4)
        $simulator->setRegister(5, U32::of($value));
        $simulator->setRegister(4, U32::of($shiftAmount));

        $simulator->executeInstruction(U16::of(0x454d));

        $this->assertSame($expected, $simulator->getRegister(5)->value);
    }

    /** @return array<string, array{int, int, int}> */
    public static function shldProvider(): array
    {
        return [
            'left shift by positive amount' => [0x00000001, 4, 0x00000010],
            'right shift by negative amount' => [0x80000000, -4 & 0xffffffff, 0x08000000],
            // Shift amount <= -32 (only low 5 bits considered, and they're zero): zero-fill.
            'full right shift zeroes out' => [0xffffffff, -32 & 0xffffffff, 0x00000000],
        ];
    }

    #[DataProvider('cmpHiProvider')]
    public function testCmpHi(int $rn, int $rm, int $expectedT): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));

        // CMP/HI R1,R2 (0011nnnnmmmm0110, n=2, m=1): T = 1 if unsigned R2 > R1.
        $simulator->setRegister(2, U32::of($rn));
        $simulator->setRegister(1, U32::of($rm));
        $simulator->executeInstruction(U16::of(0x3216));

        // MOVT R3 (0000nnnn00101001, n=3): store T into R3 so we can observe it.
        $simulator->executeInstruction(U16::of(0x0329));

        $this->assertSame($expectedT, $simulator->getRegister(3)->value);
    }

    /** @return array<string, array{int, int, int}> */
    public static function cmpHiProvider(): array
    {
        return [
            'greater is true' => [5, 3, 1],
            'equal is false' => [3, 3, 0],
            'less is false' => [3, 5, 0],
            // Unsigned: 0x80000000 outranks 1 even though it is negative as signed.
            'high bit set compares unsigned' => [0x80000000, 0x00000001, 1],
        ];
    }

    /**
     * The R0-indexed effective address is 32-bit modular: a negative index such
     * as 0xfffffffc wraps base + 0xfffffffc back to base - 4 (e.g. arr[-1]),
     * matching SH4 hardware (and flycast's u32 addr+offset).
     *
     * @param int $r0 @param int $rm @param int $ea resolved effective address
     */
    #[DataProvider('r0IndexedProvider')]
    public function testMovLLoadR0Indexed(int $r0, int $rm, int $ea): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));
        $simulator->getMemory()->writeUInt32($ea, U32::of(0xdeadbeef));

        $simulator->setRegister(0, U32::of($r0));
        $simulator->setRegister(2, U32::of($rm));

        // MOV.L @(R0,R2),R1 (0000nnnnmmmm1110, n=1, m=2)
        $simulator->executeInstruction(U16::of(0x012e));

        $this->assertSame(0xdeadbeef, $simulator->getRegister(1)->value);
    }

    #[DataProvider('r0IndexedProvider')]
    public function testMovLStoreR0Indexed(int $r0, int $rm, int $ea): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));

        $simulator->setRegister(0, U32::of($r0));
        $simulator->setRegister(2, U32::of($rm));
        $simulator->setRegister(1, U32::of(0xdeadbeef));

        // MOV.L R1,@(R0,R2) (0000nnnnmmmm0110, n=2, m=1)
        $simulator->executeInstruction(U16::of(0x0216));

        $this->assertSame(0xdeadbeef, $simulator->getMemory()->readUInt32($ea)->value);
    }

    /** @return array<string, array{int, int, int}> */
    public static function r0IndexedProvider(): array
    {
        return [
            'positive index' => [0x40, 0xc0, 0x100],
            'negative index wraps' => [0x100, -4 & 0xffffffff, 0xfc],
        ];
    }

    public function testStcSrReadsTAndTheRestOfSr(): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false), sr: 0x40000000);

        // STC SR,R1 (0000nnnn00000010, n=1)
        $simulator->executeInstruction(U16::of(0x0102));
        $this->assertSame(0x40000000, $simulator->getRegister(1)->value);

        // CMP/EQ R1,R1 (0011nnnnmmmm0000) sets T, then STC SR,R2
        $simulator->executeInstruction(U16::of(0x3110));
        $simulator->executeInstruction(U16::of(0x0202));
        $this->assertSame(0x40000001, $simulator->getRegister(2)->value);
    }

    public function testLdcSrRoundTripsItsBitsAndSetsT(): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));

        $simulator->setRegister(3, U32::of(0xffffffff));
        // LDC R3,SR (0100mmmm00001110, m=3)
        $simulator->executeInstruction(U16::of(0x430e));
        // STC SR,R4
        $simulator->executeInstruction(U16::of(0x0402));
        // MOVT R5
        $simulator->executeInstruction(U16::of(0x0529));

        $this->assertSame(0x700083f3, $simulator->getRegister(4)->value);
        $this->assertSame(1, $simulator->getRegister(5)->value);

        // IMASK changed, T cleared
        $simulator->setRegister(3, U32::of(0x400000f0));
        $simulator->executeInstruction(U16::of(0x430e));
        $simulator->executeInstruction(U16::of(0x0402));
        $simulator->executeInstruction(U16::of(0x0529));

        $this->assertSame(0x400000f0, $simulator->getRegister(4)->value);
        $this->assertSame(0, $simulator->getRegister(5)->value);
    }

    #[DataProvider('dtProvider')]
    public function testDt(int $value, int $expectedValue, int $expectedT): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));

        $simulator->setRegister(2, U32::of($value));

        // DT R2 (0100nnnn00010000, n=2)
        $simulator->executeInstruction(U16::of(0x4210));

        // MOVT R3 (0000nnnn00101001, n=3): store T into R3 so we can observe it.
        $simulator->executeInstruction(U16::of(0x0329));

        $this->assertSame($expectedValue, $simulator->getRegister(2)->value);
        $this->assertSame($expectedT, $simulator->getRegister(3)->value);
    }

    /** @return array<string, array{int, int, int}> */
    public static function dtProvider(): array
    {
        return [
            'decrement to nonzero clears T' => [5, 4, 0],
            'decrement to zero sets T' => [1, 0, 1],
            'decrement from zero wraps' => [0, 0xffffffff, 0],
        ];
    }

    #[DataProvider('mulsWProvider')]
    public function testMulsW(int $rn, int $rm, int $expectedMacl): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));

        $simulator->setRegister(2, U32::of($rn));
        $simulator->setRegister(1, U32::of($rm));

        // MULS.W R1,R2 (0010nnnnmmmm1111, n=2, m=1)
        $simulator->executeInstruction(U16::of(0x221f));

        // STS MACL,R3 (0000nnnn00011010, n=3): store MACL into R3 so we can observe it.
        $simulator->executeInstruction(U16::of(0x031a));

        $this->assertSame($expectedMacl, $simulator->getRegister(3)->value);
    }

    /** @return array<string, array{int, int, int}> */
    public static function mulsWProvider(): array
    {
        return [
            'positive times positive' => [100, 200, 20000],
            'negative times negative' => [-5 & 0xffffffff, -3 & 0xffffffff, 15],
            'positive times negative' => [1000, -2 & 0xffffffff, -2000 & 0xffffffff],
            // Only the low 16 bits of each register feed the multiply.
            'high bits of register are ignored' => [0x12340005, 0x00000002, 10],
            'largest magnitude operands' => [0xffff8000, 0xffff8000, 1073741824],
        ];
    }

    public function testPrefIsNoopOutsideStoreQueueRange(): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));
        $simulator->setRegister(5, U32::of(0x8c010000));

        // PREF @R5 (0000nnnn10000011, n=5)
        $operation = $simulator->executeInstruction(U16::of(0x0583));

        $this->assertInstanceOf(GenericOperation::class, $operation);
    }

    public function testPrefTriggersStoreQueueFlushInsideStoreQueueRange(): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));
        $simulator->setRegister(5, U32::of(0xe0001020));

        // PREF @R5 (0000nnnn10000011, n=5)
        $operation = $simulator->executeInstruction(U16::of(0x0583));

        $this->assertInstanceOf(StoreQueueFlushOperation::class, $operation);
        $this->assertSame(0xe0001020, $operation->target->value);
    }

    public function testOcbiIsObservableAndKeepsMemory(): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));
        $simulator->getMemory()->writeUInt32(0x100, U32::of(0xdeadbeef));
        $simulator->setRegister(7, U32::of(0x100));

        // OCBI @R7 (0000nnnn10010011, n=7)
        $operation = $simulator->executeInstruction(U16::of(0x0793));

        $this->assertInstanceOf(CacheBlockOperation::class, $operation);
        $this->assertSame(CacheBlockAction::Invalidate, $operation->action);
        $this->assertSame(0x100, $operation->target->value);
        $this->assertSame(0xdeadbeef, $simulator->getMemory()->readUInt32(0x100)->value);
    }

    public function testOcbwbIsObservableAndKeepsMemory(): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));
        $simulator->getMemory()->writeUInt32(0x100, U32::of(0xdeadbeef));
        $simulator->setRegister(7, U32::of(0x100));

        // OCBWB @R7 (0000nnnn10110011, n=7)
        $operation = $simulator->executeInstruction(U16::of(0x07b3));

        $this->assertInstanceOf(CacheBlockOperation::class, $operation);
        $this->assertSame(CacheBlockAction::WriteBack, $operation->action);
        $this->assertSame(0x100, $operation->target->value);
        $this->assertSame(0xdeadbeef, $simulator->getMemory()->readUInt32(0x100)->value);
    }

    public function testStoreToStoreQueueRangeIsAWrite(): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));
        $simulator->setRegister(2, U32::of(0xe0001000));
        $simulator->setRegister(1, U32::of(0xdeadbeef));

        // MOV.L R1,@R2 (0010nnnnmmmm0010, n=2, m=1)
        $operation = $simulator->executeInstruction(U16::of(0x2212));

        $this->assertInstanceOf(WriteOperation::class, $operation);
        $this->assertSame(0xe0001000, $operation->target->value);
        $this->assertSame(0xdeadbeef, $operation->value->value);
    }

    /** @return array<string, array{int}> */
    public static function qacrAddresses(): array
    {
        return ['QACR0' => [0xff000038], 'QACR1' => [0xff00003c]];
    }

    #[DataProvider('qacrAddresses')]
    public function testQacrStoreIsAWriteAndReadsBack(int $qacr): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));
        $simulator->setRegister(2, U32::of($qacr));
        $simulator->setRegister(1, U32::of(0x1c));

        // MOV.L R1,@R2 (0010nnnnmmmm0010, n=2, m=1)
        $operation = $simulator->executeInstruction(U16::of(0x2212));

        $this->assertInstanceOf(WriteOperation::class, $operation);
        $this->assertSame($qacr, $operation->target->value);
        $this->assertSame(0x1c, $operation->value->value);

        // MOV.L @R2,R3 (0110nnnnmmmm0010, n=3, m=2)
        $simulator->executeInstruction(U16::of(0x6322));

        $this->assertSame(0x1c, $simulator->getRegister(3)->value);
    }

    public function testReadOfUnsetOnChipAddressThrows(): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));
        $simulator->setRegister(2, U32::of(0xff00001c));

        $this->expectExceptionMessage('Read of unset on-chip address ff00001c');

        // MOV.L @R2,R3
        $simulator->executeInstruction(U16::of(0x6322));
    }

    public function testOnChipAddressCanBeInitialized(): void
    {
        $memory = new BinaryMemory(1024, randomize: false);
        $memory->writeUInt32(0xffd8000c, U32::of(0x1234));
        $simulator = new Simulator($memory);
        $simulator->setRegister(2, U32::of(0xffd8000c));

        // MOV.L @R2,R3
        $simulator->executeInstruction(U16::of(0x6322));

        $this->assertSame(0x1234, $simulator->getRegister(3)->value);
    }

    public function testMovLPreDecrementStoreIsAWrite(): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));
        $simulator->setRegister(4, U32::of(0x100));
        $simulator->setRegister(1, U32::of(0xdeadbeef));

        // MOV.L R1,@-R4 (0010nnnnmmmm0110, n=4, m=1)
        $operation = $simulator->executeInstruction(U16::of(0x2416));

        $this->assertInstanceOf(WriteOperation::class, $operation);
        $this->assertSame(0xfc, $operation->target->value);
        $this->assertSame(0xdeadbeef, $operation->value->value);
        $this->assertSame(0xfc, $simulator->getRegister(4)->value);
        $this->assertSame(0xdeadbeef, $simulator->getMemory()->readUInt32(0xfc)->value);
    }

    public function testMovBPreDecrementStoreIsAWrite(): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));
        $simulator->getMemory()->writeUInt32(0xfc, U32::of(0x11223344));
        $simulator->setRegister(7, U32::of(0x100));
        $simulator->setRegister(3, U32::of(0xdeadbeef));

        // MOV.B R3,@-R7 (0010nnnnmmmm0100, n=7, m=3)
        $operation = $simulator->executeInstruction(U16::of(0x2734));

        $this->assertInstanceOf(WriteOperation::class, $operation);
        $this->assertSame(0xff, $operation->target->value);
        $this->assertSame(0xef, $operation->value->value);
        $this->assertSame(0xff, $simulator->getRegister(7)->value);
        $this->assertSame(0xef223344, $simulator->getMemory()->readUInt32(0xfc)->value);
    }

    public function testMovcaLIsAWrite(): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));
        $simulator->setRegister(5, U32::of(0x100));
        $simulator->setRegister(0, U32::of(0xdeadbeef));

        // MOVCA.L R0,@R5 (0000nnnn11000011, n=5)
        $operation = $simulator->executeInstruction(U16::of(0x05c3));

        $this->assertInstanceOf(WriteOperation::class, $operation);
        $this->assertSame(0x100, $operation->target->value);
        $this->assertSame(0xdeadbeef, $operation->value->value);
        $this->assertSame(0xdeadbeef, $simulator->getMemory()->readUInt32(0x100)->value);
    }

    public function testFmovSPreDecrementStoreIsAWrite(): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));
        $simulator->setRegister(4, U32::of(0x100));
        $simulator->setFloatRegister(1, 1.0);

        // FMOV.S FR1,@-R4 (1111nnnnmmmm1011, n=4, m=1)
        $operation = $simulator->executeInstruction(U16::of(0xf41b));

        $this->assertInstanceOf(WriteOperation::class, $operation);
        $this->assertSame(0xfc, $operation->target->value);
        $this->assertSame(0x3f800000, $operation->value->value);
        $this->assertSame(0xfc, $simulator->getRegister(4)->value);
    }

    public function testMovWPostIncrementLoadSignExtends(): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));
        $simulator->getMemory()->writeUInt32(0x100, U32::of(0x0000fffe));
        $simulator->setRegister(13, U32::of(0x100));

        // MOV.W @R13+,R4 (0110nnnnmmmm0101, n=4, m=13)
        $operation = $simulator->executeInstruction(U16::of(0x64d5));

        $this->assertInstanceOf(ReadOperation::class, $operation);
        $this->assertSame(0x100, $operation->source->value);
        $this->assertSame(0xfffffffe, $simulator->getRegister(4)->value);
        $this->assertSame(0x102, $simulator->getRegister(13)->value);
    }

    public function testLdsLPostIncrementLoadsFpul(): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));
        $simulator->getMemory()->writeUInt32(0x100, U32::of(0x3f800000));
        $simulator->setRegister(0, U32::of(0x100));

        // LDS.L @R0+,FPUL
        $operation = $simulator->executeInstruction(U16::of(0x4056));
        // STS FPUL,R1
        $simulator->executeInstruction(U16::of(0x015a));

        $this->assertInstanceOf(ReadOperation::class, $operation);
        $this->assertSame(0x100, $operation->source->value);
        $this->assertSame(0x3f800000, $simulator->getRegister(1)->value);
        $this->assertSame(0x104, $simulator->getRegister(0)->value);
    }

    public function testRotlRotatesTopBitIntoBitZeroAndT(): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));
        $simulator->setRegister(0, U32::of(0x80000003));

        // ROTL R0
        $simulator->executeInstruction(U16::of(0x4004));
        // MOVT R1
        $simulator->executeInstruction(U16::of(0x0129));

        $this->assertSame(0x00000007, $simulator->getRegister(0)->value);
        $this->assertSame(1, $simulator->getRegister(1)->value);
    }

    public function testRotrRotatesBitZeroIntoTopBitAndT(): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));
        $simulator->setRegister(0, U32::of(0x00000003));

        // ROTR R0
        $simulator->executeInstruction(U16::of(0x4005));
        // MOVT R1
        $simulator->executeInstruction(U16::of(0x0129));

        $this->assertSame(0x80000001, $simulator->getRegister(0)->value);
        $this->assertSame(1, $simulator->getRegister(1)->value);
    }

    public function testRotrClearsTWhenBitZeroIsClear(): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));
        $simulator->setRegister(0, U32::of(0x80000002));

        // ROTR R0
        $simulator->executeInstruction(U16::of(0x4005));
        // MOVT R1
        $simulator->executeInstruction(U16::of(0x0129));

        $this->assertSame(0x40000001, $simulator->getRegister(0)->value);
        $this->assertSame(0, $simulator->getRegister(1)->value);
    }

    public function testNotComplementsEveryBit(): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));
        $simulator->setRegister(2, U32::of(0x0000f0a5));

        // NOT R2,R1
        $simulator->executeInstruction(U16::of(0x6127));

        $this->assertSame(0xffff0f5a, $simulator->getRegister(1)->value);
        $this->assertSame(0x0000f0a5, $simulator->getRegister(2)->value);
    }

    public function testRotclShiftsTInAndTopBitOut(): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));
        $simulator->setRegister(0, U32::of(0x80000002));
        $simulator->setRegister(2, U32::of(1));

        // SHLR R2 (T = 1)
        $simulator->executeInstruction(U16::of(0x4201));
        // ROTCL R0
        $simulator->executeInstruction(U16::of(0x4024));
        // MOVT R1
        $simulator->executeInstruction(U16::of(0x0129));

        $this->assertSame(0x00000005, $simulator->getRegister(0)->value);
        $this->assertSame(1, $simulator->getRegister(1)->value);
    }

    public function testRotclWithTClear(): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));
        $simulator->setRegister(0, U32::of(0x00000003));
        $simulator->setRegister(2, U32::of(2));

        // SHLR R2 (T = 0)
        $simulator->executeInstruction(U16::of(0x4201));
        // ROTCL R0
        $simulator->executeInstruction(U16::of(0x4024));
        // MOVT R1
        $simulator->executeInstruction(U16::of(0x0129));

        $this->assertSame(0x00000006, $simulator->getRegister(0)->value);
        $this->assertSame(0, $simulator->getRegister(1)->value);
    }

    public function testMulLKeepsLow32BitsOfOverflowingProduct(): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));
        $simulator->setRegister(0, U32::of(0xffffffff));
        $simulator->setRegister(1, U32::of(3));
        $simulator->setRegister(3, U32::of(0xfffffffd));
        $simulator->setRegister(4, U32::of(0xffffffff));

        // MUL.L R1,R0
        $simulator->executeInstruction(U16::of(0x0017));
        // STS MACL,R2
        $simulator->executeInstruction(U16::of(0x021a));
        // MUL.L R4,R3
        $simulator->executeInstruction(U16::of(0x0347));
        // STS MACL,R5
        $simulator->executeInstruction(U16::of(0x051a));

        $this->assertSame(0xfffffffd, $simulator->getRegister(2)->value);
        $this->assertSame(0x00000003, $simulator->getRegister(5)->value);
    }

    public function testShadRightShiftKeepsSign(): void
    {
        $simulator = new Simulator(new BinaryMemory(1024, randomize: false));
        $simulator->setRegister(0, U32::of(0x80000010));
        $simulator->setRegister(1, U32::of(-22 & 0xffffffff));
        $simulator->setRegister(2, U32::of(0x80000000));
        $simulator->setRegister(3, U32::of(0xffffffe0));
        $simulator->setRegister(4, U32::of(0x40000000));
        $simulator->setRegister(5, U32::of(-22 & 0xffffffff));

        // SHAD R1,R0 (by -22)
        $simulator->executeInstruction(U16::of(0x401c));
        // SHAD R3,R2 (full right shift)
        $simulator->executeInstruction(U16::of(0x423c));
        // SHAD R5,R4 (by -22, positive value)
        $simulator->executeInstruction(U16::of(0x445c));

        $this->assertSame(0xfffffe00, $simulator->getRegister(0)->value);
        $this->assertSame(0xffffffff, $simulator->getRegister(2)->value);
        $this->assertSame(0x00000100, $simulator->getRegister(4)->value);
    }
}
