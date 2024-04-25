<?php

declare(strict_types=1);

namespace Lhsazevedo\Sh4ObjTest\Simulator\Exceptions;

class ExpectationException extends \Exception
{
    public function __construct(string $message = 'Expectation failed')
    {
        parent::__construct($message);
    }
}

