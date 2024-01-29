<?php

declare(strict_types=1);

use Lhsazevedo\Objsim\TestCase;

return new class extends TestCase {
    protected string $objectFile = __DIR__ . '/main.obj';

    public function testState0x00Init_SkipTitleAnimationWhenStartIsPressed() {
        $this->shouldReadSymbolOffset('_menuState_8c1bc7a8', 0x18, 0x0b);
        // peripherals[0].press (sizeof PERIPHERAL = 52)
        $this->shouldReadSymbolOffset('_peripheral_8c1ba35c', 16, 8);

        $this->shouldReadSymbolOffset('_midiHandles_8c0fcd28', 0, 0xbebacafe);

        $this->shouldCall('_sdMidiPlay')->with(0xbebacafe, 1, 0, 0);

        $this->shouldWriteSymbolOffset('_peripheral_8c1ba35c', 16, 0);
        $this->shouldWriteSymbolOffset('_menuState_8c1bc7a8', 0x18, 0x0e);
        $this->shouldWriteSymbolOffset('_isFading_8c226568', 0, 0);

        $this->forceStop();

        $this->call('_task_title_8c015ab8')
            ->with(0, 0)
            ->run();
    }

    public function testState0x00Init_AdvanceToFortyFiveFadeIn() {
        $this->shouldReadSymbolOffset('_menuState_8c1bc7a8', 0x18, 0);
        $this->shouldReadSymbolOffset('_menuState_8c1bc7a8', 0x18, 0);

        // peripherals[0].press (sizeof PERIPHERAL = 52)
        //$this->shouldReadSymbolOffset('peripheral_8c1ba35c', 16, 0);

        $this->shouldCall('_getUknPvmBool_8c01432a')->andReturn(0);
        $this->shouldCall('_FUN_8c011f7e');
        $this->shouldCall('_FUN_8c01940e');

        // TODO: Fix Task size
        $taskPtr = $this->alloc(0x0c);
        $this->shouldRead($taskPtr + 0x08, 0);

        $this->shouldWriteSymbolOffset('_menuState_8c1bc7a8', 0x18, 1);

        $this->shouldCall('_push_fadein_8c022a9c')->with(20);

        $this->shouldCall('_njSetBackColor')->with(0xff000000, 0xff000000, 0xff000000);

        $this->call('_task_title_8c015ab8')
            ->with($taskPtr, 0)
            ->run();
    }

    public function testState0x00Init_NoopWhenUknPvmBoolIsTrue() {
        $this->shouldReadSymbolOffset('_menuState_8c1bc7a8', 0x18, 0);
        $this->shouldReadSymbolOffset('_menuState_8c1bc7a8', 0x18, 0);

        // peripherals[0].press (sizeof PERIPHERAL = 52)
        //$this->shouldReadSymbolOffset('peripheral_8c1ba35c', 16, 0);

        $this->shouldCall('_getUknPvmBool_8c01432a')->andReturn(1);

        // TODO: Fix Task size
        $taskPtr = $this->alloc(0x0c);

        $this->call('_task_title_8c015ab8')
            ->with($taskPtr, 0)
            ->run();
    }

    public function testState0x00Init_SkipToTitleFadeInDirectWhenTaskField0x08IsTrue() {
        $this->shouldReadSymbolOffset('_menuState_8c1bc7a8', 0x18, 0);
        $this->shouldReadSymbolOffset('_menuState_8c1bc7a8', 0x18, 0);

        // peripherals[0].press (sizeof PERIPHERAL = 52)
        //$this->shouldReadSymbolOffset('peripheral_8c1ba35c', 16, 0);

        $this->shouldCall('_getUknPvmBool_8c01432a')->andReturn(0);
        $this->shouldCall('_FUN_8c011f7e');
        $this->shouldCall('_FUN_8c01940e');

        // TODO: Fix Task size
        $taskPtr = $this->alloc(0x0c);
        $this->shouldRead($taskPtr + 0x08, 1);

        $this->shouldWriteSymbolOffset('_menuState_8c1bc7a8', 0x18, 0x0d);

        $this->shouldCall('_push_fadein_8c022a9c')->with(10);

        $this->shouldCall('_njSetBackColor')->with(0xffffffff, 0xffffffff, 0xffffffff);

        $this->call('_task_title_8c015ab8')
            ->with($taskPtr, 0)
            ->run();
    }

    public function testState0x01FortyfiveFadeIn_WaitsForFadeInBeforeAdvancing() {
        $menuStatePtr = $this->alloc(0x6c);
        $this->rellocate('_menuState_8c1bc7a8', $menuStatePtr);

        $this->shouldRead($menuStatePtr + 0x18, 1);
        $this->shouldRead($menuStatePtr + 0x18, 1);

        $this->shouldReadSymbolOffset('_isFading_8c226568', 0, 1);

        $this->shouldCall('_drawSprite_8c014f54')->with($menuStatePtr + 0x0c, 0, 0.0, 0.0, -5.0);

        $this->call('_task_title_8c015ab8')
            ->with(0, 0)
            ->run();
    }

    public function testState0x01FortyfiveFadeIn_AdvancesWhenFadeInIsOver() {
        $menuStatePtr = $this->alloc(0x6c);
        $this->rellocate('_menuState_8c1bc7a8', $menuStatePtr);

        $this->shouldRead($menuStatePtr + 0x18, 1);
        $this->shouldRead($menuStatePtr + 0x18, 1);

        $this->shouldReadSymbolOffset('_isFading_8c226568', 0, 0);

        // Advance title state
        $this->shouldWrite($menuStatePtr + 0x18, 2);
        // Init logo timer
        $this->shouldWrite($menuStatePtr + 0x68, 0);

        $this->shouldCall('_drawSprite_8c014f54')->with($menuStatePtr + 0x0c, 0, 0.0, 0.0, -5.0);

        $this->call('_task_title_8c015ab8')
            ->with(0, 0)
            ->run();
    }
};
