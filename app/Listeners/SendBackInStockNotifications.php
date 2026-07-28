<?php

namespace App\Listeners;

use App\Events\ProductRestocked;
use App\Models\Product;
use App\Models\ProductStockNotificationRequest;
use App\Models\User;
use App\Notifications\ProductBackInStockNotification;
use App\Support\NotificationDuplicateKeyDetector;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\QueryException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

class SendBackInStockNotifications implements ShouldQueueAfterCommit, ShouldBeUnique
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
    public function uniqueId(ProductRestocked $event): string
    {
        return 'back-in-stock-notifications:' . $event->eventId;
    }

    /**
     * Handle the event.
     */
    public function handle(ProductRestocked $event): void
    {
        $product = Product::find($event->productId);

        if (! $product || (int) $product->available_stock <= 0) {
            return;
        }

        ProductStockNotificationRequest::where('product_id', $event->productId)
            ->chunkById(100, function ($requests) use ($event) {
                foreach ($requests as $request) {
                    $user = User::find($request->user_id);

                    if (! $user) {
                        $request->delete();
                        continue;
                    }

                    $notification = new ProductBackInStockNotification($event, $user->id);

                    $exists = DB::table('notifications')
                        ->where('id', $notification->id)
                        ->exists();

                    if ($exists) {
                        $request->delete();
                        continue;
                    }

                    try {
                        $user->notify($notification);
                        $request->delete();
                    } catch (QueryException $e) {
                        if ($this->isDuplicateNotificationIdException($e)) {
                            $request->delete();
                            continue;
                        }

                        throw $e;
                    }
                }
            });
    }

    /**
     * Determine if the query exception is a duplicate notification ID constraint violation.
     */
    private function isDuplicateNotificationIdException(QueryException $exception): bool
    {
        return NotificationDuplicateKeyDetector::isDuplicateNotificationIdException($exception);
    }
}
