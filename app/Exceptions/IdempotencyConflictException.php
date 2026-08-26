<?php

namespace App\Exceptions;

use RuntimeException;

class IdempotencyConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Kunci idempotensi sudah digunakan untuk checkout yang berbeda.');
    }
}
