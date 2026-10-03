<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Actions;

use App\Domains\Clinical\Models\MessageThread;
use App\Domains\Clinical\Models\Referral;
use App\Domains\Clinical\Models\ThreadMessage;
use App\Domains\Clinical\Support\AccessLog;
use App\Domains\Clinical\Support\PracticeCrypto;
use App\Domains\Hub\Actions\ShareConsent;
use App\Domains\Hub\Models\HubEscript;
use App\Domains\Hub\Models\HubLabOrder;
use App\Domains\Hub\Models\HubLink;
use App\Domains\Identity\Models\Staff;
use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Patients\Models\Patient;
use App\Domains\Platform\Models\Provider;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Clinician-to-clinician messaging about a patient, inside a practice or across
 * practices. Cross-practice conversations need the patient linked to both plus
 * share-history consent, or an active referral, e-script or lab order between
 * them. Each practice keeps its own encrypted copy; the Hub keeps nothing.
 */
class ClinicianMessaging
{
    /**
     * @param  list<int>  $staffIds
     */
    public function start(Staff $by, string $subject, ?Patient $patient, array $staffIds, ?string $otherTenantId = null, ?string $contextType = null, ?string $contextId = null, bool $urgent = false): MessageThread
    {
        $here = $this->provider();
        if ($otherTenantId !== null) {
            if ($patient === null) {
                throw ValidationException::withMessages(['patient_id' => 'Conversations with other practices must be about a patient.']);
            }
            $this->assertCrossPracticeAllowed($patient, $here, $otherTenantId);
        }

        $thread = MessageThread::create([
            'patient_id' => $patient?->id, 'subject' => trim($subject), 'context_type' => $contextType, 'context_id' => $contextId,
            'other_tenant_id' => $otherTenantId, 'local_staff_ids' => array_values(array_unique([$by->id, ...$staffIds])),
            'urgent' => $urgent, 'urgent_due_at' => $urgent ? now()->addHours(4) : null,
        ]);

        if ($otherTenantId !== null && $patient !== null) {
            $identityId = (string) $patient->getAttribute('hub_identity_id');
            $remoteId = Provider::query()->findOrFail($otherTenantId)->run(function () use ($thread, $here, $identityId, $subject, $contextType, $contextId, $urgent): string {
                $local = Patient::query()->where('hub_identity_id', $identityId)->firstOrFail();
                $remote = MessageThread::create([
                    'patient_id' => $local->id, 'subject' => trim($subject), 'context_type' => $contextType, 'context_id' => $contextId,
                    'other_tenant_id' => $here->id, 'other_thread_id' => $thread->id, 'local_staff_ids' => $this->clinicians(),
                    'urgent' => $urgent, 'urgent_due_at' => $urgent ? now()->addHours(4) : null,
                ]);
                AccessLog::record($local->id, 'discussed', "{$here->name} started a conversation with this practice about your care: {$subject}");

                return $remote->id;
            });
            $thread->forceFill(['other_thread_id' => $remoteId])->save();
        }

        if ($patient !== null) {
            AccessLog::record($patient->id, 'discussed', 'Clinicians discussed your care: '.trim($subject));
        }

        return $thread;
    }

    public function post(MessageThread $thread, Staff $sender, string $body, ?string $attachmentPath = null, bool $visibleToPatient = false, ?int $correctsId = null, ?string $sharedItem = null): ThreadMessage
    {
        if (trim($body) === '') {
            throw ValidationException::withMessages(['body' => 'Write a message.']);
        }
        if (! in_array($sender->id, $thread->local_staff_ids, true)) {
            throw ValidationException::withMessages(['body' => 'You are not part of this conversation.']);
        }
        if ($correctsId !== null && ! $thread->messages()->whereKey($correctsId)->exists()) {
            throw ValidationException::withMessages(['corrects_id' => 'You can only correct a message in this conversation.']);
        }

        $here = $this->provider();
        $label = "{$sender->name} ({$here->name})";
        $message = $this->store($thread, $sender->id, $label, $body, $attachmentPath, $visibleToPatient, $correctsId, $sharedItem);

        if ($thread->other_tenant_id !== null && $thread->other_thread_id !== null) {
            Provider::query()->findOrFail($thread->other_tenant_id)->run(function () use ($thread, $label, $body, $sharedItem): void {
                $remote = MessageThread::query()->findOrFail($thread->other_thread_id);
                $this->store($remote, null, $label, $body, null, false, null, $sharedItem);
                $this->notify($remote, $label);
            });
        }
        $this->notify($thread, $label, $sender->id);

        if ($sharedItem !== null && $thread->patient_id !== null) {
            AccessLog::record($thread->patient_id, 'shared', "{$sharedItem} was shared in a conversation about your care");
        }

        return $message;
    }

    public function markRead(MessageThread $thread, Staff $reader): void
    {
        foreach ($thread->messages()->get() as $m) {
            /** @var ThreadMessage $m */
            $read = $m->read_by ?? [];
            if (! in_array($reader->id, $read, true)) {
                $m->forceFill(['read_by' => [...$read, $reader->id]])->save();
            }
        }
    }

    public function file(ThreadMessage $message): void
    {
        $message->forceFill(['filed_at' => $message->filed_at ?? now()])->save();
    }

    /**
     * Urgent conversations with no reply by the due time go to the covering doctor.
     */
    public function escalateUrgent(): int
    {
        $count = 0;
        MessageThread::query()->where('urgent', true)->whereNull('escalated_at')->where('urgent_due_at', '<', now())->get()
            ->each(function (MessageThread $t) use (&$count): void {
                $lastFromUs = $t->messages()->whereNotNull('sender_staff_id')->max('created_at');
                $lastFromThem = $t->messages()->whereNull('sender_staff_id')->max('created_at');
                if ($lastFromUs !== null && ($lastFromThem === null || $lastFromUs < $lastFromThem)) {
                    return;
                }
                $covers = Staff::query()->whereIn('id', $t->local_staff_ids)->whereNotNull('covering_staff_id')->pluck('covering_staff_id')->all();
                $t->forceFill(['escalated_at' => now(), 'local_staff_ids' => array_values(array_unique([...$t->local_staff_ids, ...array_map('intval', $covers)]))])->save();
                $this->notify($t, 'Escalated: no reply to an urgent message');
                $count++;
            });

        return $count;
    }

    private function store(MessageThread $thread, ?int $senderId, string $label, string $body, ?string $attachment, bool $visible, ?int $corrects, ?string $shared): ThreadMessage
    {
        return $thread->messages()->create([
            'sender_staff_id' => $senderId, 'sender_label' => $label, 'body' => PracticeCrypto::encrypt(trim($body)),
            'attachment_path' => $attachment, 'visible_to_patient' => $visible, 'corrects_id' => $corrects, 'shared_item' => $shared,
            'read_by' => $senderId === null ? [] : [$senderId], 'created_at' => now(),
        ]);
    }

    private function notify(MessageThread $thread, string $from, ?int $except = null): void
    {
        if (! $thread->urgent) {
            return;
        }
        $emails = User::query()->whereIn('id', array_diff($thread->local_staff_ids, [$except]))->pluck('email');
        foreach ($emails as $email) {
            app(SendMessage::class)->handle('email', (string) $email, "Urgent clinical message from {$from}. Sign in to Clinic Flow to read it.", 'Urgent message: '.$thread->subject, 'message_thread', $thread->id);
        }
    }

    /**
     * @return list<int>
     */
    private function clinicians(): array
    {
        return Staff::query()->get()->filter(fn (Staff $s) => $s->hasAnyRole(['doctor', 'locum_doctor', 'pharmacist', 'lab_technician', 'owner']))->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    private function assertCrossPracticeAllowed(Patient $patient, Provider $here, string $otherTenantId): void
    {
        $identity = $patient->getAttribute('hub_identity_id');
        $linked = fn (string $tenant) => is_string($identity) && HubLink::query()->where('identity_id', $identity)->where('tenant_id', $tenant)->where('status', 'active')->exists();
        if (! $linked($here->id) || ! $linked($otherTenantId)) {
            throw ValidationException::withMessages(['other_tenant_id' => 'The patient must be linked to both practices on the network.']);
        }

        $consent = app(ShareConsent::class)->categories($identity, $otherTenantId) !== [];
        $context = Referral::query()->where('patient_id', $patient->id)->where('other_tenant_id', $otherTenantId)->whereNotIn('status', ['declined', 'cancelled'])->exists()
            || HubEscript::query()->where('identity_id', $identity)->whereIn('status', ['sent', 'accepted'])->where(fn ($q) => $q->where('pharmacy_tenant_id', $otherTenantId)->orWhere('issuer_tenant_id', $otherTenantId))->exists()
            || HubLabOrder::query()->where('identity_id', $identity)->whereNotIn('status', ['rejected'])->where(fn ($q) => $q->where('lab_tenant_id', $otherTenantId)->orWhere('issuer_tenant_id', $otherTenantId))->exists();

        if (! $consent && ! $context) {
            throw ValidationException::withMessages(['other_tenant_id' => 'You can message another practice about this patient only with their share consent, or an active referral, e-script or lab order between you.']);
        }
    }

    private function provider(): Provider
    {
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);

        return $provider;
    }
}
