<?php

use App\Notifications\CompanyInvitationNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;

test('invitation notification is queued after commit and renders safe mail content', function () {
    $acceptUrl = 'http://localhost/invitations/11111111-1111-4111-8111-111111111111?token=plain-secret-token';
    $notification = new CompanyInvitationNotification(
        'Example Company AB',
        'Invitation Owner',
        'editor',
        '2026-07-16T12:00:00+02:00',
        $acceptUrl,
    );
    $mail = $notification->toMail(new AnonymousNotifiable);
    $rendered = (string) $mail->render();

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->afterCommit)->toBeTrue()
        ->and($mail->subject)->toBe('You have been invited to join Example Company AB on NordiPass')
        ->and($mail->actionUrl)->toBe($acceptUrl)
        ->and($rendered)->toContain('Example Company AB')
        ->and($rendered)->toContain('Invitation Owner')
        ->and($rendered)->toContain('editor')
        ->and($rendered)->toContain('2026-07-16')
        ->and($rendered)->not->toContain(hash('sha256', 'plain-secret-token'))
        ->and($rendered)->not->toContain('company_id')
        ->and($rendered)->not->toContain('invited_by');
});
