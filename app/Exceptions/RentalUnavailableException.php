<?php

namespace App\Exceptions;

use RuntimeException;

class RentalUnavailableException extends RuntimeException
{
    public function __construct(public readonly string $productName)
    {
        parent::__construct("Produk {$productName} tidak tersedia pada tanggal yang dipilih.");
    }
}
