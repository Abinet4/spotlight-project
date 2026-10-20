<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email sent when a sign-in detail changes, so the owner notices a change they did not make.
 * Always sent, whatever the user's alert settings say.
 */
class SecurityNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    /**
     * @param  'email_changed'|'password_changed'|'two_step_on'|'two_step_off'|'two_step_reset'|'phone_changed'|'phone_added'  $kind
     * @param  ?string  $detail  the new email, or the masked phone for the two-step notices
     */
    public function __construct(public string $kind, public string $name, public ?string $detail = null)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = config('marketplace.name');
        $mail = (new MailMessage)->greeting('Hello '.$this->name.',');

        match ($this->kind) {
            'email_changed' => $mail->subject("Your {$brand} email address was changed")
                ->line("The email address on your {$brand} account was changed to {$this->detail}. Emails will go there from now on."),
            'two_step_on' => $mail->subject("Two-step sign-in is on for your {$brand} account")
                ->line("From now on, signing in also needs a code texted to {$this->detail}."),
            'two_step_off' => $mail->subject("Two-step sign-in was turned off on your {$brand} account")
                ->line('Signing in now needs only your password.'),
            'two_step_reset' => $mail->subject("Two-step sign-in was turned off on your {$brand} account")
                ->line("A {$brand} administrator turned off two-step sign-in and removed your checked phone number, which is done when an account owner has lost their phone. Signing in now needs only your password. You can turn it on again in My account."),
            'phone_added' => $mail->subject("A phone was added to your {$brand} account")
                ->line("While signing in, {$this->detail} was checked as the phone for your {$brand} account. Sign-in codes will be texted there."),
            'phone_changed' => $mail->subject("The phone for your {$brand} sign-in codes was changed")
                ->line("Sign-in codes for your {$brand} account will now be texted to {$this->detail}."),
            default => $mail->subject("Your {$brand} password was changed")
                ->line("The password for your {$brand} account was changed, and every other device was signed out."),
        };

        return $mail->line('If you did not do this, reply to this email or contact us right away.')
            ->salutation('Thank you, '.$brand);
    }
}
