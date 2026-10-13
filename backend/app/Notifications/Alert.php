<?php

namespace App\Notifications;

use App\Models\Setting;
use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One alert to one person, built by App\Services\Alerts. In-app alerts are written at once;
 * email and SMS go through the queue so a slow or failing provider never blocks an order.
 */
class Alert extends Notification implements ShouldQueue
{
    use Queueable;

    public const CHANNELS = ['database', 'mail', 'sms'];

    public int $tries = 3;

    /** @var list<int> seconds before each retry */
    public array $backoff = [60, 300];

    /**
     * @param  list<'database'|'mail'|'sms'>  $channels  where this kind of alert may go
     * @param  string|null  $url  frontend path, e.g. /orders/12
     * @param  list<string>  $mailLines  extra paragraphs for the email
     * @param  string|null  $fallbackPhone  number to text when the account has no mobile number (the order's phone)
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url,
        public readonly array $channels,
        public readonly ?string $sms = null,
        public readonly array $mailLines = [],
        public readonly ?string $fallbackPhone = null,
    ) {
        $this->afterCommit();
    }

    public function via(User $notifiable): array
    {
        return array_values(array_filter(
            array_map(fn ($c) => $c === 'sms' ? SmsChannel::class : $c, $this->channels),
            fn ($channel) => $this->shouldSend($notifiable, $channel),
        ));
    }

    /**
     * Checked again when a queued email or SMS is about to go out, so a suspension or a changed
     * preference in the meantime is respected.
     */
    public function shouldSend(User $notifiable, string $channel): bool
    {
        if (! $notifiable->isActive()) {
            return false;
        }

        return match ($channel) {
            'database' => true,
            'mail' => $notifiable->alert_email && filled($notifiable->email),
            SmsChannel::class => $notifiable->alert_sms && Setting::smsEnabled() && $notifiable->routeNotificationForSms($this) !== null,
            default => false,
        };
    }

    /** In-app alerts are cheap and must show up straight away, so they skip the queue. */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    public function databaseType(object $notifiable): string
    {
        return $this->kind;
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'body' => $this->body, 'url' => $this->url];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title)
            ->greeting('Hello '.self::plain($notifiable->name).',')
            ->line(self::plain($this->body));
        foreach ($this->mailLines as $line) {
            $mail->line(self::plain($line));
        }
        if ($this->url) {
            $mail->action('Open in '.config('marketplace.name'), self::link($this->url));
        }

        return $mail->salutation('Thank you, '.config('marketplace.name'));
    }

    public function toSms(User $notifiable): string
    {
        $text = $this->sms ?? $this->title.'. '.$this->body;

        return mb_substr($text, 0, 480);
    }

    /**
     * Email lines are Markdown, and they carry text people typed (names, quote titles, cancel reasons).
     * Show that text as typed: it must not add links, pictures, headings or code to an email sent in the
     * marketplace's name. HTML is already escaped by the mail template.
     */
    public static function plain(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $text = (string) preg_replace('/[\\\\`*_\[\]~|]/', '\\\\$0', $text);

        // A line starting like a heading or a list.
        return (string) preg_replace(['/^([#+=-])/', '/^(\d+)([.)])/'], ['\\\\$1', '$1\\\\$2'], $text);
    }

    public static function link(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }
}
