<?php

declare(strict_types=1);

namespace App\Domains\Prescribing\Actions;

use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Models\Staff;
use App\Domains\Prescribing\Models\Prescription;
use App\Domains\Prescribing\Models\SigningChallenge;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Sends the prescriber a one-time signing PIN (advanced electronic signature:
 * the PIN ties the signature to the doctor's own phone).
 */
class RequestSigningPin
{
    public function __construct(private readonly OtpSender $sender, private readonly CheckPrescriptionSafety $safety) {}

    public function handle(Prescription $prescription, Staff $doctor): void
    {
        if ($prescription->status !== Prescription::DRAFT) {
            throw ValidationException::withMessages(['prescription' => 'Only a draft prescription can be signed.']);
        }
        if ($prescription->prescriber_staff_id !== $doctor->id) {
            throw ValidationException::withMessages(['prescription' => 'Only the prescribing doctor can sign this script.']);
        }
        if (! $this->safety->canSign($prescription, $this->safety->handle($prescription))) {
            throw ValidationException::withMessages(['prescription' => 'Resolve the safety warnings before signing.']);
        }

        $user = User::query()->findOrFail($doctor->id);
        $code = (string) random_int(100000, 999999);

        SigningChallenge::query()->where('prescription_id', $prescription->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        SigningChallenge::create([
            'prescription_id' => $prescription->id,
            'staff_id' => $doctor->id,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->sender->send($user, $code);
    }
}
