<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class InvitationResponseNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $email,
        public string $organizationName,
        public string $response,
        public ?string $reason = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Invitation '.$this->response,
            'message' => $this->email.' has '.$this->response.' the invitation to '.$this->organizationName.'.'.($this->reason ? " Reason: ".$this->reason : ''),
            'url' => '/messages',
        ];
    }
}
