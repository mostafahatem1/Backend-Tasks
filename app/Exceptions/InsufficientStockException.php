<?php

namespace App\Exceptions;

use Exception;
use Throwable;

class InsufficientStockException extends Exception
{
    public array $unavailableItems;

    public function __construct(
        array $unavailableItems,
        string $message = 'One or more products do not have enough stock.',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->unavailableItems = $unavailableItems;
    }

    public function getUnavailableItems(): array
    {
        return $this->unavailableItems;
    }
}
