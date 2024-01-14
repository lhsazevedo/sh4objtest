struct GlobalStruct {
    int field_0x00;
    int field_0x04;
    int field_0x08;
    int field_0x0c;
    int field_0x10;
    int field_0x14;
    int field_0x18;
    int field_0x1c;
} typedef GlobalStruct;

extern GlobalStruct global_struct;

int main() {
        if (global_struct.field_0x18) {
            return 1;
        }

        return 0;
}
