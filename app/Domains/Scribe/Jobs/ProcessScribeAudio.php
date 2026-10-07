<?php

declare(strict_types=1);

namespace App\Domains\Scribe\Jobs;

use App\Domains\Platform\Storage\FileStore;
use App\Domains\Scribe\Actions\AiScribe;
use App\Domains\Telemedicine\Events\CallStateChanged;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Transcribes and drafts one AI scribe recording in the background (practice database via the
 * tenancy queue bootstrapper). The encrypted audio is deleted before transcription starts.
 * One attempt only, so a retry can never charge twice; billing happens only after a successful transcription.
 */
class ProcessScribeAudio implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 280;

    public function __construct(public string $sessionId, public string $mime, public int $claimedSeconds)
    {
        $this->onQueue('ai');
    }

    public function handle(AiScribe $scribe): void
    {
        $disk = Storage::disk(FileStore::DISK);
        $path = AiScribe::audioPath($this->sessionId);
        $stored = $disk->get($path);
        $disk->delete($path);
        if ($stored === null) {
            $this->markFailed('The recording was not found.');

            return;
        }
        try {
            $scribe->process($this->sessionId, (string) base64_decode(Crypt::decryptString($stored), true), $this->mime, $this->claimedSeconds);
        } catch (ValidationException) {
            // process() has already recorded the failure on the session; nothing was charged unless transcription succeeded.
        }
        CallStateChanged::forScribeSession($this->sessionId);
    }

    public function failed(?\Throwable $e): void
    {
        Storage::disk(FileStore::DISK)->delete(AiScribe::audioPath($this->sessionId));
        DB::table('scribe_sessions')->where('id', $this->sessionId)->where('status', 'processing')
            ->update(['status' => 'failed', 'error' => 'The recording could not be processed. Nothing was charged.', 'updated_at' => now()]);
    }

    private function markFailed(string $message): void
    {
        DB::table('scribe_sessions')->where('id', $this->sessionId)->where('status', 'processing')->update(['status' => 'failed', 'error' => $message, 'updated_at' => now()]);
    }
}
