// #include <shinobi.h>
#include <shinobi.h>
#include <sg_sd.h>

extern SDMIDI midiHandles_8c0fcd28[7];
extern PDS_PERIPHERAL peripheral_8c1ba35c[2];

#ifndef NO_REFACTOR
int cycleOptionAndPlaySound_8c016c58(int *currentOption, int optionCount)
{
    int newOption = *currentOption;
    int press = peripheral_8c1ba35c[0].press; 

    if (press & PDD_DGT_KL) {
        newOption = --newOption + optionCount;
    } else if (press & PDD_DGT_KR) {
        newOption++;
    } else {
        return 0;
    }

    sdMidiPlay(midiHandles_8c0fcd28[0], 1, 3, 0);
    *currentOption = newOption % optionCount;
    return 1;
}
#else
int cycleOptionAndPlaySound_8c016c58(int *currentOption, int optionCount)
{
    int newOption = *currentOption;

    if (peripheral_8c1ba35c[0].press & PDD_DGT_KL) {
        newOption--;
        if (newOption < 0) {
            newOption = optionCount - 1;
        }

        sdMidiPlay(midiHandles_8c0fcd28[0], 1, 3, 0);
    } else if (peripheral_8c1ba35c[0].press & PDD_DGT_KR) {
        newOption++;
        if (newOption >= optionCount) {
            newOption = 0;
        }

        sdMidiPlay(midiHandles_8c0fcd28[0], 1, 3, 0);
    } else {
        return 0;
    }

    *currentOption = newOption;
    return 1;
}
#endif

int FUN_8c016caa(int* param_1) {
    if (*param_1 == 0) {
        if (peripheral_8c1ba35c[0].press & PDD_DGT_KR) {
            *param_1 = 1;
            sdMidiPlay(midiHandles_8c0fcd28[0], 1, 3, 0);
        }
    } else if (peripheral_8c1ba35c[0].press & PDD_DGT_KL) {
        *param_1 = 0;
        sdMidiPlay(midiHandles_8c0fcd28[0], 1, 3, 0);
    }

    if (peripheral_8c1ba35c[0].press & PDD_DGT_TA) {
        if (!*param_1) {
            sdMidiPlay(midiHandles_8c0fcd28[0], 1, 0, 0);
            return 1;
        } else {
            sdMidiPlay(midiHandles_8c0fcd28[0], 1, 1, 0);
            return 2;
        }
    } else if (peripheral_8c1ba35c[0].press & PDD_DGT_TB) {
        sdMidiPlay(midiHandles_8c0fcd28[0], 1, 1, 0);
        return 2;
    } else {
        return 0;
    }

    return 1;
}
