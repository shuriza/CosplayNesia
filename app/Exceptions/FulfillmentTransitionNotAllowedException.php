<?php

namespace App\Exceptions;

use RuntimeException;

class FulfillmentTransitionNotAllowedException extends RuntimeException
{
    public function __construct(string $message = 'Perubahan status pesanan tidak diizinkan.')
    {
        parent::__construct($message);
    }
}
