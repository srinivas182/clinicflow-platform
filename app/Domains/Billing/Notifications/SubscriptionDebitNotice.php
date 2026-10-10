<?php

declare(strict_types=1);

namespace App\Domains\Billing\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the provider owner about an auto-debit: paid, failed (with the next
 * retry), or stopped after the last retry.
 */
class SubscriptionDebitNotice extends Notification
{
    use Queueable;

    public function __construct(
        public string $outcome,
        public string $invoiceNumber,
        public string $amount,
        public string $card,
        public ?string $detail = null,
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
        $mail = (new MailMessage)->subject(match ($this->outcome) {
            'paid' => "Dr Business Flow subscription paid — {$this->invoiceNumber}",
            'failed' => "Dr Business Flow could not collect {$this->invoiceNumber}",
            default => "Automatic payment stopped — {$this->invoiceNumber}",
        });

        return match ($this->outcome) {
            'paid' => $mail->line("We collected {$this->amount} from your {$this->card} for invoice {$this->invoiceNumber}. Thank you."),
            'failed' => $mail->line("We could not collect {$this->amount} from your {$this->card} for invoice {$this->invoiceNumber}.")
                ->line($this->detail ?? '')
                ->line('We will try again automatically. You can also pay now from Settings → Subscription.'),
            default => $mail->line("We tried three times to collect {$this->amount} for invoice {$this->invoiceNumber} and stopped.")
                ->line('Please pay from Settings → Subscription. Your workspace becomes read-only 7 days after the due date if the invoice stays unpaid.'),
        };
    }
}
