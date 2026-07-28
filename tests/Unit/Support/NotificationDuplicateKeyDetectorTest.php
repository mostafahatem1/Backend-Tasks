<?php

namespace Tests\Unit\Support;

use App\Support\NotificationDuplicateKeyDetector;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\TestCase;

class NotificationDuplicateKeyDetectorTest extends TestCase
{
    public function test_mysql_duplicate_notifications_id_returns_true(): void
    {
        $exception = new QueryException(
            'mysql',
            'insert into `notifications` (`id`, `type`) values (?, ?)',
            ['123', 'order_status_changed'],
            new \Exception("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '123' for key 'notifications.PRIMARY'")
        );

        $this->assertTrue(NotificationDuplicateKeyDetector::isDuplicateNotificationIdException($exception));
    }

    public function test_postgres_duplicate_notifications_pkey_returns_true(): void
    {
        $exception = new QueryException(
            'pgsql',
            'insert into "notifications" ("id", "type") values (?, ?)',
            ['123', 'order_status_changed'],
            new \Exception('SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "notifications_pkey"')
        );

        $this->assertTrue(NotificationDuplicateKeyDetector::isDuplicateNotificationIdException($exception));
    }

    public function test_sqlite_duplicate_notifications_id_returns_true(): void
    {
        $exception = new QueryException(
            'sqlite',
            'insert into "notifications" ("id", "type") values (?, ?)',
            ['123', 'order_status_changed'],
            new \Exception('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: notifications.id')
        );

        $this->assertTrue(NotificationDuplicateKeyDetector::isDuplicateNotificationIdException($exception));
    }

    public function test_mysql_foreign_key_violation_returns_false(): void
    {
        $exception = new QueryException(
            'mysql',
            'insert into `notifications` (`id`, `notifiable_id`) values (?, ?)',
            ['123', 999],
            new \Exception('SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails')
        );

        $this->assertFalse(NotificationDuplicateKeyDetector::isDuplicateNotificationIdException($exception));
    }

    public function test_sqlite_not_null_violation_returns_false(): void
    {
        $exception = new QueryException(
            'sqlite',
            'insert into "notifications" ("id", "data") values (?, ?)',
            ['123', null],
            new \Exception('SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed: notifications.data')
        );

        $this->assertFalse(NotificationDuplicateKeyDetector::isDuplicateNotificationIdException($exception));
    }

    public function test_duplicate_primary_key_for_orders_returns_false(): void
    {
        $exception = new QueryException(
            'mysql',
            'insert into `orders` (`id`, `user_id`) values (?, ?)',
            [1, 2],
            new \Exception("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1' for key 'orders.PRIMARY'")
        );

        $this->assertFalse(NotificationDuplicateKeyDetector::isDuplicateNotificationIdException($exception));
    }

    public function test_duplicate_unique_key_for_unrelated_table_returns_false(): void
    {
        $exception = new QueryException(
            'mysql',
            'insert into `users` (`phone`) values (?)',
            ['+1234567890'],
            new \Exception("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '+1234567890' for key 'users.users_phone_unique'")
        );

        $this->assertFalse(NotificationDuplicateKeyDetector::isDuplicateNotificationIdException($exception));
    }
}
