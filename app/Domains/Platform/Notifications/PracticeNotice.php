<?php

declare(strict_types=1);

namespace App\Domains\Platform\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A short account email to a practice owner or the platform team: a subject, a few lines and,
 * optionally, one button. Used for approval, verification, subscription and support notices.
 */
class PracticeNotice extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public string $subject,
        public array $lines,
        public ?string $actionLabel = null,
        public ?string $actionUrl = null,
        public string $kind = 'general',
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject);
        foreach ($this->lines as $line) {
            $mail->line($line);
        }
        if ($this->actionLabel !== null && $this->actionUrl !== null) {
            $mail->action($this->actionLabel, $this->actionUrl);
        }

        return $mail;
    }
}
