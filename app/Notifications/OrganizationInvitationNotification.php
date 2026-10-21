<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class OrganizationInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(public string $organizationName, public string $invitationUrl) {}

    public function via(object $notifiable): array
    {
        return $notifiable instanceof \App\Models\User ? ['mail', 'database'] : ['mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Organization invitation',
            'message' => 'You have been invited to join '.$this->organizationName.' on WayAlong. Open the invitation to review and accept.',
            'url' => $this->invitationUrl,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Invitation to join '.$this->organizationName.' on WayAlong')
            ->line('You have been invited to join '.$this->organizationName.' on WayAlong.')
            ->action('Accept invitation', $this->invitationUrl)
            ->line('This invitation expires in seven days. If you did not expect it, you can ignore this email.');
    }
}
