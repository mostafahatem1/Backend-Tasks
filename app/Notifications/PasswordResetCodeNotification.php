<?php

namespace App\Notifications;

use App\Notifications\Channels\LogPhoneChannel;
use Illuminate\Notifications\Notification;

class PasswordResetCodeNotification extends Notification
{
    public string $code;
    public int $expiresInMinutes = 10;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $code)
    {
        $this->code = $code;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string|class-string>
     */
    public function via(mixed $notifiable): array
    {
        return [LogPhoneChannel::class];
    }

    /**
     * Get the log payload representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toLogPhone(mixed $notifiable): array
    {
        return [
            'phone' => $notifiable->phone,
            'code' => $this->code,
            'expires_in_minutes' => $this->expiresInMinutes,
            'purpose' => 'password_reset',
        ];
    }
}
