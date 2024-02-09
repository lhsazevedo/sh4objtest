<?php

declare(strict_types=1);

use Lhsazevedo\Sh4ObjTest\TestCase;
use Lhsazevedo\Sh4ObjTest\WildcardArgument;

function fdec(float $value) {
    return unpack('L', pack('f', $value))[1];
}

return new class extends TestCase {
    public function testFUN_8c01306e_DemoIsNot2()
    {
        $matrixPtr = $this->allocRellocate('_var_matrix_8c2f8ca0', 0x04);

        $var_8c18ad28Ptr = $this->alloc(0x14);
        $this->initUint8($var_8c18ad28Ptr + 0x08, 0x10);
        $this->initUint8($var_8c18ad28Ptr + 0x09, 0x20);
        $this->initUint8($var_8c18ad28Ptr + 0x0a, 0x30);
        $this->initUint8($var_8c18ad28Ptr + 0x0b, 0x40);
        $this->initUint32($var_8c18ad28Ptr + 0x0c, fdec(42.0));
        $this->initUint32($var_8c18ad28Ptr + 0x10, fdec(43.0));

        $var_8c18ad28PtrPtr = $this->allocRellocate('_var_8c18ad28', 4);
        $this->initUint32($var_8c18ad28PtrPtr, $var_8c18ad28Ptr);

        $var_fogTable_8c18aaf8Ptr = $this->allocRellocate('_var_fogTable_8c18aaf8', 4);

        $var_tasks_8c1ba3c8Ptr = $this->allocRellocate('_var_tasks_8c1ba3c8', 4);
        $var_tasks_8c1ba5e8Ptr = $this->allocRellocate('_var_tasks_8c1ba5e8', 4);
        $var_tasks_8c1ba808Ptr = $this->allocRellocate('_var_tasks_8c1ba808', 4);
        $var_tasks_8c1bac28Ptr = $this->allocRellocate('_var_tasks_8c1bac28', 4);
        $var_tasks_8c1bb448Ptr = $this->allocRellocate('_var_tasks_8c1bb448', 4);

        $var_seed_8c157a64Ptr = $this->allocRellocate('_var_seed_8c157a64', 4);
        $this->initUint32($var_seed_8c157a64Ptr, 0xcafe0001);

        $var_demo_8c1bb8d0Ptr = $this->allocRellocate('_var_demo_8c1bb8d0', 4);
        $this->initUint32($var_demo_8c1bb8d0Ptr, 1);

        $task_8c012cbcPtr = $this->allocRellocate('_task_8c012cbc', 4);
        $task_8c01677ePtr = $this->allocRellocate('_task_8c01677e', 4);

        $var_8c1bb8ccPtr = $this->allocRellocate('_var_8c1bb8cc', 4);
        $var_8c22847cPtr = $this->allocRellocate('_var_8c22847c', 4);

        $this->shouldCall('_njInitMatrix')->with($matrixPtr, 16, 0);
        $this->shouldCall('_njSetBackColor')->with(0, 0, 0);
        $this->shouldCall('_njSetFogColor')->with(0x40302010);
        $this->shouldCall('_njGenerateFogTable3')->with($var_fogTable_8c18aaf8Ptr, 42.0, 43.0);
        $this->shouldCall('_njFogEnable');
        $this->shouldCall('_kmSetCheapShadowMode')->with(0x80);
        $this->shouldCall('_kmSetFogTable')->with($var_fogTable_8c18aaf8Ptr);

        $this->shouldCall('_clearTasks_8c014a9c')->with($var_tasks_8c1ba5e8Ptr, 0x10);
        $this->shouldCall('_clearTasks_8c014a9c')->with($var_tasks_8c1ba808Ptr, 0x20);
        $this->shouldCall('_clearTasks_8c014a9c')->with($var_tasks_8c1bac28Ptr, 0x40);
        $this->shouldCall('_clearTasks_8c014a9c')->with($var_tasks_8c1bb448Ptr, 0x20);

        // njRandomSeed
        $this->shouldCall('_srand')->with(0xcafe0001);
        $this->shouldCall('_FUN_8c012160')->with(0xcafe0001);
        $this->shouldCall('_FUN_8c0121a2')->with(0xcafe0001);
        
        $this->shouldCall('_FUN_8c0128cc')->with(1);

        $this->shouldCall('_pushTask_8c014ae8')->with($var_tasks_8c1ba3c8Ptr, $task_8c012cbcPtr, 0xFFFFE7, 0xFFFFEB, 0);
        $this->shouldCall('_pushTask_8c014ae8')->with($var_tasks_8c1ba5e8Ptr, $task_8c01677ePtr, 0xFFFFE7, 0xFFFFEB, 0);

        $this->shouldWrite($var_8c1bb8ccPtr, 0);
        $this->shouldWrite($var_8c22847cPtr, 0);

        $this->shouldCall('_FUN_8c023610');
        $this->shouldCall('_FUN_8c02845a');
        
        $this->shouldCall('_FUN_8c029920');
        
        $this->shouldCall('_FUN_8c0296d6');
        $this->shouldCall('_FUN_8c02769e');
        $this->shouldCall('_FUN_8c0222dc');
        $this->shouldCall('_FUN_8c02a6ac');
        $this->shouldCall('_FUN_8c02c46a');
        $this->shouldCall('_FUN_8c02018c');
        $this->shouldCall('_FUN_8c02d968');
        $this->shouldCall('_FUN_8c020528');

        $this->shouldCall('_pushTask_8c014ae8')->with(
            $var_tasks_8c1ba5e8Ptr,
            new WildcardArgument,
            0xFFFFE7,
            0xFFFFEB,
            0
        );

        $createdTask = $this->alloc(0x0c);
        $this->shouldRead(0xFFFFE7, $createdTask);
        $this->shouldWrite($createdTask + 0x08, 0);

        $this->shouldCall('_FUN_8c0228a2');

        $this->call('_FUN_8c01306e')->run();
    }

    public function testFUN_8c01306e_DemoIs2_8c1bb8d4Is0()
    {
        $matrixPtr = $this->allocRellocate('_var_matrix_8c2f8ca0', 0x04);

        $var_8c18ad28Ptr = $this->alloc(0x14);
        $this->initUint8($var_8c18ad28Ptr + 0x08, 0x10);
        $this->initUint8($var_8c18ad28Ptr + 0x09, 0x20);
        $this->initUint8($var_8c18ad28Ptr + 0x0a, 0x30);
        $this->initUint8($var_8c18ad28Ptr + 0x0b, 0x40);
        $this->initUint32($var_8c18ad28Ptr + 0x0c, fdec(42.0));
        $this->initUint32($var_8c18ad28Ptr + 0x10, fdec(43.0));

        $var_8c18ad28PtrPtr = $this->allocRellocate('_var_8c18ad28', 4);
        $this->initUint32($var_8c18ad28PtrPtr, $var_8c18ad28Ptr);

        $var_fogTable_8c18aaf8Ptr = $this->allocRellocate('_var_fogTable_8c18aaf8', 4);

        $var_tasks_8c1ba3c8Ptr = $this->allocRellocate('_var_tasks_8c1ba3c8', 4);
        $var_tasks_8c1ba5e8Ptr = $this->allocRellocate('_var_tasks_8c1ba5e8', 4);
        $var_tasks_8c1ba808Ptr = $this->allocRellocate('_var_tasks_8c1ba808', 4);
        $var_tasks_8c1bac28Ptr = $this->allocRellocate('_var_tasks_8c1bac28', 4);
        $var_tasks_8c1bb448Ptr = $this->allocRellocate('_var_tasks_8c1bb448', 4);

        $var_seed_8c157a64Ptr = $this->allocRellocate('_var_seed_8c157a64', 4);
        $this->initUint32($var_seed_8c157a64Ptr, 0xcafe0001);

        $var_demo_8c1bb8d0Ptr = $this->allocRellocate('_var_demo_8c1bb8d0', 4);
        $this->initUint32($var_demo_8c1bb8d0Ptr, 2);

        $var_8c1bb8d4Ptr = $this->allocRellocate('_var_8c1bb8d4', 4);
        $this->initUint32($var_8c1bb8d4Ptr, 0);

        $task_8c012d06Ptr = $this->allocRellocate('_task_8c012d06', 4);
        $task_8c016bf4Ptr = $this->allocRellocate('_task_8c016bf4', 4);

        $var_8c1bb8ccPtr = $this->allocRellocate('_var_8c1bb8cc', 4);
        $var_8c22847cPtr = $this->allocRellocate('_var_8c22847c', 4);

        $this->shouldCall('_njInitMatrix')->with($matrixPtr, 16, 0);
        $this->shouldCall('_njSetBackColor')->with(0, 0, 0);
        $this->shouldCall('_njSetFogColor')->with(0x40302010);
        $this->shouldCall('_njGenerateFogTable3')->with($var_fogTable_8c18aaf8Ptr, 42.0, 43.0);
        $this->shouldCall('_njFogEnable');
        $this->shouldCall('_kmSetCheapShadowMode')->with(0x80);
        $this->shouldCall('_kmSetFogTable')->with($var_fogTable_8c18aaf8Ptr);

        $this->shouldCall('_clearTasks_8c014a9c')->with($var_tasks_8c1ba5e8Ptr, 0x10);
        $this->shouldCall('_clearTasks_8c014a9c')->with($var_tasks_8c1ba808Ptr, 0x20);
        $this->shouldCall('_clearTasks_8c014a9c')->with($var_tasks_8c1bac28Ptr, 0x40);
        $this->shouldCall('_clearTasks_8c014a9c')->with($var_tasks_8c1bb448Ptr, 0x20);

        // njRandomSeed
        $this->shouldCall('_srand')->with(0xcafe0001);
        $this->shouldCall('_FUN_8c012160')->with(0xcafe0001);
        $this->shouldCall('_FUN_8c0121a2')->with(0xcafe0001);
        
        $this->shouldCall('_FUN_8c0128cc')->with(1);

        $this->shouldCall('_pushTask_8c014ae8')->with($var_tasks_8c1ba3c8Ptr, $task_8c012d06Ptr, 0xFFFFE7, 0xFFFFEB, 0);
        $this->shouldCall('_pushTask_8c014ae8')->with($var_tasks_8c1ba5e8Ptr, $task_8c016bf4Ptr, 0xFFFFE7, 0xFFFFEB, 0);
        $this->shouldCall('_FUN_8c025af4');

        $this->shouldWrite($var_8c1bb8ccPtr, 0);
        $this->shouldWrite($var_8c22847cPtr, 0);

        $this->shouldCall('_FUN_8c023610');
        $this->shouldCall('_FUN_8c02845a');
        
        $this->shouldCall('_FUN_8c0296d6');
        $this->shouldCall('_FUN_8c02769e');
        $this->shouldCall('_FUN_8c0222dc');
        $this->shouldCall('_FUN_8c02a6ac');
        $this->shouldCall('_FUN_8c02c46a');
        $this->shouldCall('_FUN_8c02018c');
        $this->shouldCall('_FUN_8c02d968');
        $this->shouldCall('_FUN_8c020528');

        $this->shouldCall('_pushTask_8c014ae8')->with(
            $var_tasks_8c1ba5e8Ptr,
            new WildcardArgument,
            0xFFFFE7,
            0xFFFFEB,
            0
        );
        $createdTask = $this->alloc(0x0c);
        $this->shouldRead(0xFFFFE7, $createdTask);
        $this->shouldWrite($createdTask + 0x08, 0);

        $this->shouldCall('_FUN_8c0228a2');

        $this->call('_FUN_8c01306e')->run();
    }

    public function testFUN_8c01306e_DemoIs2_8c1bb8d4Is1()
    {
        $matrixPtr = $this->allocRellocate('_var_matrix_8c2f8ca0', 0x04);

        $var_8c18ad28Ptr = $this->alloc(0x14);
        $this->initUint8($var_8c18ad28Ptr + 0x08, 0x10);
        $this->initUint8($var_8c18ad28Ptr + 0x09, 0x20);
        $this->initUint8($var_8c18ad28Ptr + 0x0a, 0x30);
        $this->initUint8($var_8c18ad28Ptr + 0x0b, 0x40);
        $this->initUint32($var_8c18ad28Ptr + 0x0c, fdec(42.0));
        $this->initUint32($var_8c18ad28Ptr + 0x10, fdec(43.0));

        $var_8c18ad28PtrPtr = $this->allocRellocate('_var_8c18ad28', 4);
        $this->initUint32($var_8c18ad28PtrPtr, $var_8c18ad28Ptr);

        $var_fogTable_8c18aaf8Ptr = $this->allocRellocate('_var_fogTable_8c18aaf8', 4);

        $var_tasks_8c1ba3c8Ptr = $this->allocRellocate('_var_tasks_8c1ba3c8', 4);
        $var_tasks_8c1ba5e8Ptr = $this->allocRellocate('_var_tasks_8c1ba5e8', 4);
        $var_tasks_8c1ba808Ptr = $this->allocRellocate('_var_tasks_8c1ba808', 4);
        $var_tasks_8c1bac28Ptr = $this->allocRellocate('_var_tasks_8c1bac28', 4);
        $var_tasks_8c1bb448Ptr = $this->allocRellocate('_var_tasks_8c1bb448', 4);

        $var_seed_8c157a64Ptr = $this->allocRellocate('_var_seed_8c157a64', 4);
        $this->initUint32($var_seed_8c157a64Ptr, 0xcafe0001);

        $var_demo_8c1bb8d0Ptr = $this->allocRellocate('_var_demo_8c1bb8d0', 4);
        $this->initUint32($var_demo_8c1bb8d0Ptr, 2);

        $var_8c1bb8d4Ptr = $this->allocRellocate('_var_8c1bb8d4', 4);
        $this->initUint32($var_8c1bb8d4Ptr, 1);

        $task_8c012d5aPtr = $this->allocRellocate('_task_8c012d5a', 4);
        $task_8c016bf4Ptr = $this->allocRellocate('_task_8c016bf4', 4);

        $var_8c1bb8ccPtr = $this->allocRellocate('_var_8c1bb8cc', 4);
        $var_8c22847cPtr = $this->allocRellocate('_var_8c22847c', 4);

        $this->shouldCall('_njInitMatrix')->with($matrixPtr, 16, 0);
        $this->shouldCall('_njSetBackColor')->with(0, 0, 0);
        $this->shouldCall('_njSetFogColor')->with(0x40302010);
        $this->shouldCall('_njGenerateFogTable3')->with($var_fogTable_8c18aaf8Ptr, 42.0, 43.0);
        $this->shouldCall('_njFogEnable');
        $this->shouldCall('_kmSetCheapShadowMode')->with(0x80);
        $this->shouldCall('_kmSetFogTable')->with($var_fogTable_8c18aaf8Ptr);

        $this->shouldCall('_clearTasks_8c014a9c')->with($var_tasks_8c1ba5e8Ptr, 0x10);
        $this->shouldCall('_clearTasks_8c014a9c')->with($var_tasks_8c1ba808Ptr, 0x20);
        $this->shouldCall('_clearTasks_8c014a9c')->with($var_tasks_8c1bac28Ptr, 0x40);
        $this->shouldCall('_clearTasks_8c014a9c')->with($var_tasks_8c1bb448Ptr, 0x20);

        // njRandomSeed
        $this->shouldCall('_srand')->with(0xcafe0001);
        $this->shouldCall('_FUN_8c012160')->with(0xcafe0001);
        $this->shouldCall('_FUN_8c0121a2')->with(0xcafe0001);
        
        $this->shouldCall('_FUN_8c0128cc')->with(1);

        $this->shouldCall('_pushTask_8c014ae8')->with($var_tasks_8c1ba3c8Ptr, $task_8c012d5aPtr, 0xFFFFE7, 0xFFFFEB, 0);

        $createdTask = $this->alloc(0x0c);
        $this->shouldRead(0xFFFFE7, $createdTask);
        $this->shouldWrite($createdTask + 0x08, 0);
        $this->shouldRead(0xFFFFE7, $createdTask);
        $this->shouldWrite($createdTask + 0x0c, 0);
    
        $this->shouldCall('_pushTask_8c014ae8')->with($var_tasks_8c1ba5e8Ptr, $task_8c016bf4Ptr, 0xFFFFE7, 0xFFFFEB, 0);
        $this->shouldCall('_FUN_8c025af4');

        $this->shouldWrite($var_8c1bb8ccPtr, 0);
        $this->shouldWrite($var_8c22847cPtr, 0);

        $this->shouldCall('_FUN_8c023610');
        $this->shouldCall('_FUN_8c02845a');
        
        $this->shouldCall('_FUN_8c0296d6');
        $this->shouldCall('_FUN_8c02769e');
        $this->shouldCall('_FUN_8c0222dc');
        $this->shouldCall('_FUN_8c02a6ac');
        $this->shouldCall('_FUN_8c02c46a');
        $this->shouldCall('_FUN_8c02018c');
        $this->shouldCall('_FUN_8c02d968');
        $this->shouldCall('_FUN_8c020528');

        $this->shouldCall('_pushTask_8c014ae8')->with(
            $var_tasks_8c1ba5e8Ptr,
            new WildcardArgument,
            0xFFFFE7,
            0xFFFFEB,
            0
        );
        $createdTask = $this->alloc(0x0c);
        $this->shouldRead(0xFFFFE7, $createdTask);
        $this->shouldWrite($createdTask + 0x08, 0);

        $this->shouldCall('_FUN_8c0228a2');

        $this->call('_FUN_8c01306e')->run();
    }

    private function allocRellocate($name, $size)
    {
        $ptr = $this->alloc($size);
        $this->rellocate($name, $ptr);
        return $ptr;
    }
};
