<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class DocumentExpirationNotification extends Notification
{
    use Queueable;

    public function __construct(public string $documentTitle, public string $expirationDate, public string $window) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->window === 'expired' ? 'Client document expired' : 'Client document expires soon',
            'message' => 'A document at one of your authorized facilities requires attention. Open the appropriate client record to review it.',
            'url' => '/dashboard',
        ];
    }
}
