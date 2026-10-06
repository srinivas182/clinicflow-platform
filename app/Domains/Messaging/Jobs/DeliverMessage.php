<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Jobs;

use App\Domains\Messaging\Actions\SendMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Delivers one SMS or email in the background. Runs in the practice's own database
 * (tenancy queue bootstrapper). Up to three attempts; the message log shows the outcome.
 */
class DeliverMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public int $timeout = 60;

    public function __construct(
        public string $channel,
        public string $recipient,
        public string $body,
        public ?string $subject,
        public ?string $relatedType,
        public ?string $relatedId,
        public ?int $logId,
    ) {
        $this->onQueue('messages');
    }

    public function handle(SendMessage $sender): void
    {
        if ($sender->deliver($this->channel, $this->recipient, $this->body, $this->subject, $this->relatedType, $this->relatedId, $this->logId)) {
            return;
        }
        if ($this->attempts() < $this->tries) {
            $this->markLog('queued');
            $this->release($this->backoff[$this->attempts() - 1] ?? 600);
        }
    }

    public function failed(?\Throwable $e): void
    {
        $this->markLog('failed');
    }

    private function markLog(string $status): void
    {
        if ($this->logId !== null && tenant() !== null) {
            DB::table('message_log')->where('id', $this->logId)->update(['status' => $status]);
        }
    }
}
