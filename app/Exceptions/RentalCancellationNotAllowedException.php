<?php

namespace App\Exceptions;

use RuntimeException;

class RentalCancellationNotAllowedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Reservasi sewa ini tidak dapat dibatalkan.');
    }
}
