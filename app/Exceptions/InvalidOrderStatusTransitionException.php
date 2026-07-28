<?php

namespace App\Exceptions;

use App\Enums\OrderStatus;
use Exception;
use Throwable;

class InvalidOrderStatusTransitionException extends Exception
{
    public OrderStatus $currentStatus;
    public OrderStatus $requestedStatus;
    public array $allowedStatuses;

    public function __construct(
        OrderStatus $currentStatus,
        OrderStatus $requestedStatus,
        string $message = 'Invalid order status transition.',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->currentStatus = $currentStatus;
        $this->requestedStatus = $requestedStatus;
        $this->allowedStatuses = $currentStatus->allowedTransitions();
    }
}
