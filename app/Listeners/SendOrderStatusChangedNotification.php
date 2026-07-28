<?php

namespace App\Listeners;

use App\Events\OrderStatusChanged;
use App\Models\User;
use App\Notifications\OrderStatusChangedNotification;
use App\Support\NotificationDuplicateKeyDetector;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\QueryException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

class SendOrderStatusChangedNotification implements ShouldQueueAfterCommit, ShouldBeUnique
{
    use InteractsWithQueue;

    /**
     * The name of the queue the job should be sent to.
     */
    public string $queue = 'notifications';

    /**
     * The number of times the queued listener may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 60];

    /**
     * Get the unique identifier for the queued listener lock.
     */
    public function uniqueId(OrderStatusChanged $event): string
    {
        return 'order-status-notification:' . $event->historyId;
    }

    /**
     * Handle the event.
     */
    public function handle(OrderStatusChanged $event): void
    {
        $owner = User::find($event->ownerUserId);

        if (! $owner) {
            return;
        }

        $notification = new OrderStatusChangedNotification($event, $owner->id);

        $exists = DB::table('notifications')
            ->where('id', $notification->id)
            ->exists();

        if ($exists) {
            return;
        }

        try {
            $owner->notify($notification);
        } catch (QueryException $e) {
            if ($this->isDuplicateNotificationIdException($e)) {
                return;
            }

            throw $e;
        }
    }

    /**
     * Determine if the query exception is a duplicate notification ID constraint violation.
     */
    private function isDuplicateNotificationIdException(QueryException $exception): bool
    {
        return NotificationDuplicateKeyDetector::isDuplicateNotificationIdException($exception);
    }
}
