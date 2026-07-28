<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case PROCESSING = 'processing';
    case SHIPPED = 'shipped';
    case DELIVERED = 'delivered';
    case CANCELLED = 'cancelled';

    /**
     * Get the allowed target status transitions from the current status.
     *
     * @return array<int, OrderStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDING => [self::CONFIRMED, self::CANCELLED],
            self::CONFIRMED => [self::PROCESSING, self::CANCELLED],
            self::PROCESSING => [self::SHIPPED, self::CANCELLED],
            self::SHIPPED => [self::DELIVERED],
            self::DELIVERED => [],
            self::CANCELLED => [],
        };
    }

    /**
     * Determine if a transition to the requested status is allowed.
     */
    public function canTransitionTo(OrderStatus $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }
}
