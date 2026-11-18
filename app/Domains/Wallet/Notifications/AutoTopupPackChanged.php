<?php

declare(strict_types=1);

namespace App\Domains\Wallet\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the practice owner that the top-up pack chosen for automatic top-up no longer
 * exists, and which pack is used instead (or that automatic top-up was paused).
 */
class AutoTopupPackChanged extends Notification
{
    use Queueable;

    public function __construct(public string $oldPack, public ?string $newPack) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject('Automatic wallet top-up changed')
            ->line("The {$this->oldPack} top-up pack you chose for automatic top-up is no longer offered.");

        return $this->newPack === null
            ? $mail->line('No top-up packs are offered at the moment, so automatic top-up is paused. Choose a pack in Settings → Wallet when packs are available again.')
            : $mail->line("From now on, automatic top-up uses the {$this->newPack} pack, the closest to your choice.")
                ->line('You can choose another pack in Settings → Wallet.');
    }
}
