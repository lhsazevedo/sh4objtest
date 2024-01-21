extern check(int val);

void main(int four)
{
    check(four == 4);
    check(four == 3);
    check(four == 5);

    check(four != 4);
    check(four != 3);
    check(four != 5);

    check(four > 4);
    check(four > 3);
    check(four > 5);

    check(four < 4);
    check(four < 3);
    check(four < 5);

    check(four >= 4);
    check(four >= 3);
    check(four >= 5);

    check(four <= 4);
    check(four <= 3);
    check(four <= 5);
}
