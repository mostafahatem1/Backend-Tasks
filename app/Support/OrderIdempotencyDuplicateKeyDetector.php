<?php

namespace App\Support;

use Illuminate\Database\QueryException;

class OrderIdempotencyDuplicateKeyDetector
{
    public static function isDuplicateKey(QueryException $e): bool
    {
        $message = $e->getMessage();
        $errorInfo = $e->errorInfo;

        $sqlState = $errorInfo[0] ?? null;
        $driverCode = $errorInfo[1] ?? null;

        // MySQL check
        if ($sqlState === '23000' && (int) $driverCode === 1062) {
            return str_contains($message, 'orders_user_id_idempotency_key_unique');
        }

        // PostgreSQL check
        if ($sqlState === '23505') {
            return str_contains($message, 'orders_user_id_idempotency_key_unique');
        }

        // SQLite check
        if (str_contains($message, 'UNIQUE constraint failed: orders.user_id, orders.idempotency_key')) {
            return true;
        }

        return false;
    }
}
