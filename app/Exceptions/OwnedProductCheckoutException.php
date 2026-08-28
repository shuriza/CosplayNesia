<?php

namespace App\Exceptions;

use RuntimeException;

class OwnedProductCheckoutException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Produk milik sendiri tidak dapat dibeli atau disewa.');
    }
}
