<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CompanyInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        private readonly string $companyName,
        private readonly string $inviterName,
        private readonly string $role,
        private readonly string $expiresAt,
        private readonly string $acceptUrl,
    ) {
        $this->afterCommit();
        $this->onQueue('mail');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->mailer((string) config('invitations.mailer', 'array'))
            ->subject("You have been invited to join {$this->companyName} on NordiPass")
            ->greeting('You have a NordiPass invitation')
            ->line("{$this->inviterName} invited you to join {$this->companyName} as {$this->role}.")
            ->line("This invitation expires {$this->expiresAt}.")
            ->action('Review invitation', $this->acceptUrl)
            ->line('If you were not expecting this invitation, you can safely ignore this email.');
    }

    /**
     * @return array<string, bool>
     */
    public function __debugInfo(): array
    {
        return ['sensitive_payload' => true];
    }
}
