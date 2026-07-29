<?php

namespace Tests\Unit\Support;

use App\Support\OrderIdempotencyDuplicateKeyDetector;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;

class OrderIdempotencyDuplicateKeyDetectorTest extends TestCase
{
    private function makeQueryException(string $message, ?string $sqlState = null, ?int $driverCode = null): QueryException
    {
        $pdoException = new PDOException($message);
        if ($sqlState !== null || $driverCode !== null) {
            $pdoException->errorInfo = [$sqlState, $driverCode, $message];
        }

        return new QueryException('test', 'INSERT INTO orders ...', [], $pdoException);
    }

    public function test_mysql_exact_unique_constraint_returns_true(): void
    {
        $message = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1-test-key' for key 'orders_user_id_idempotency_key_unique'";
        $exception = $this->makeQueryException($message, '23000', 1062);

        $this->assertTrue(OrderIdempotencyDuplicateKeyDetector::isDuplicateKey($exception));
    }

    public function test_postgresql_exact_unique_constraint_returns_true(): void
    {
        $message = 'ERROR: duplicate key value violates unique constraint "orders_user_id_idempotency_key_unique"';
        $exception = $this->makeQueryException($message, '23505', 7);

        $this->assertTrue(OrderIdempotencyDuplicateKeyDetector::isDuplicateKey($exception));
    }

    public function test_sqlite_exact_composite_unique_failure_returns_true(): void
    {
        $message = 'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: orders.user_id, orders.idempotency_key';
        $exception = $this->makeQueryException($message, '23000', 19);

        $this->assertTrue(OrderIdempotencyDuplicateKeyDetector::isDuplicateKey($exception));
    }

    public function test_notifications_id_duplicate_returns_false(): void
    {
        $message = 'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: notifications.id';
        $exception = $this->makeQueryException($message, '23000', 19);

        $this->assertFalse(OrderIdempotencyDuplicateKeyDetector::isDuplicateKey($exception));
    }

    public function test_orders_primary_key_duplicate_returns_false(): void
    {
        $message = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1' for key 'orders.PRIMARY'";
        $exception = $this->makeQueryException($message, '23000', 1062);

        $this->assertFalse(OrderIdempotencyDuplicateKeyDetector::isDuplicateKey($exception));
    }

    public function test_foreign_key_violation_returns_false(): void
    {
        $message = 'SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails';
        $exception = $this->makeQueryException($message, '23000', 1452);

        $this->assertFalse(OrderIdempotencyDuplicateKeyDetector::isDuplicateKey($exception));
    }

    public function test_unrelated_unique_constraint_returns_false(): void
    {
        $message = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'test@example.com' for key 'users_email_unique'";
        $exception = $this->makeQueryException($message, '23000', 1062);

        $this->assertFalse(OrderIdempotencyDuplicateKeyDetector::isDuplicateKey($exception));
    }
}
