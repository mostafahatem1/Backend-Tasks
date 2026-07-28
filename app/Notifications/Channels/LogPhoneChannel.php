<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class LogPhoneChannel
{
    /**
     * Send the given notification.
     */
    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toLogPhone')) {
            return;
        }

        $data = $notification->toLogPhone($notifiable);

        $payload = [
            'phone' => $data['phone'] ?? null,
            'code' => $data['code'] ?? null,
            'expires_in_minutes' => $data['expires_in_minutes'] ?? null,
        ];

        if (isset($data['purpose'])) {
            $payload['purpose'] = $data['purpose'];
        }

        Log::info('Phone code notification', $payload);
    }
}
