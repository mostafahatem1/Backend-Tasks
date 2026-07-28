<?php

namespace App\Support;

use Illuminate\Database\QueryException;

class NotificationDuplicateKeyDetector
{
    /**
     * Determine if the query exception is a genuine duplicate primary-key violation for notifications.id.
     */
    public static function isDuplicateNotificationIdException(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());
        $sql = strtolower($exception->getSql());

        $targetsNotificationsTable = str_contains($sql, 'notifications') || str_contains($message, 'notifications');

        if (! $targetsNotificationsTable) {
            return false;
        }

        // SQLite: UNIQUE constraint failed: notifications.id
        if (str_contains($message, 'unique constraint failed: notifications.id')) {
            return true;
        }

        // MySQL: SQLSTATE 23000, error code 1062, insert into notifications, duplicate entry for key 'PRIMARY' or 'notifications.PRIMARY'
        $isMysql1062 = (
            str_contains($message, '23000') ||
            $exception->getCode() === '23000' ||
            (isset($exception->errorInfo[0]) && $exception->errorInfo[0] === '23000')
        ) && (
            str_contains($message, '1062') ||
            (isset($exception->errorInfo[1]) && (int) $exception->errorInfo[1] === 1062)
        );

        if ($isMysql1062) {
            $isInsertQuery = str_contains($sql, 'insert into') || str_contains($message, 'insert into') || $sql === '';
            $isPrimaryKey = str_contains($message, 'primary') || str_contains($message, 'notifications.primary');

            if ($isInsertQuery && $isPrimaryKey) {
                return true;
            }
        }

        // PostgreSQL: SQLSTATE 23505 (Unique violation) for notifications_pkey or notifications.id
        $isPostgres23505 = str_contains($message, '23505') ||
            $exception->getCode() === '23505' ||
            (isset($exception->errorInfo[0]) && $exception->errorInfo[0] === '23505');

        if ($isPostgres23505) {
            $isNotificationsPrimaryKey = str_contains($message, 'notifications_pkey') ||
                str_contains($message, 'notifications.id') ||
                (str_contains($message, 'notifications') && str_contains($message, 'pkey'));

            if ($isNotificationsPrimaryKey) {
                return true;
            }
        }

        return false;
    }
}
