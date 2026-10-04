<?php

declare(strict_types=1);

namespace App\Domains\Locums\Actions;

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Membership;
use App\Domains\Platform\Models\Provider;
use App\Domains\Scheduling\Models\RosterSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Locum marketplace. Verified locum doctors apply for shifts that practices
 * post; an accepted locum gets access to that practice only during the shift
 * window (from 1 hour before to 12 hours after) and a roster session so
 * patients can be booked. Payment for the shift is between practice and locum.
 */
class LocumMarketplace
{
    public const BEFORE_MINUTES = 60;

    public const AFTER_HOURS = 12;

    public const REQUIRED_DOCUMENTS = ['hpcsa', 'indemnity'];

    private function db(): ConnectionInterface
    {
        return DB::connection((string) config('tenancy.database.central_connection'));
    }

    /**
     * @param  array{hpcsa_number: string, qualifications: string, languages: list<string>, areas: list<string>, hourly_rate_cents: ?int, bio: ?string, vat_number?: ?string, alerts_email?: bool, alerts_sms?: bool}  $data
     */
    public function saveProfile(User $user, array $data): int
    {
        if (preg_match('/^MP\s?\d{6,7}$/i', trim($data['hpcsa_number'])) !== 1) {
            throw ValidationException::withMessages(['hpcsa_number' => 'Enter an HPCSA medical practitioner number, e.g. MP0123456.']);
        }
        $existing = $this->db()->table('locum_profiles')->where('user_id', $user->id)->first();
        $row = ['hpcsa_number' => strtoupper(str_replace(' ', '', trim($data['hpcsa_number']))), 'qualifications' => trim($data['qualifications']),
            'languages' => json_encode($data['languages']), 'areas' => json_encode($data['areas']),
            'hourly_rate_cents' => $data['hourly_rate_cents'], 'bio' => $data['bio'], 'updated_at' => now(),
            'vat_number' => isset($data['vat_number']) && preg_match('/^4\d{9}$/', (string) $data['vat_number']) === 1 ? $data['vat_number'] : null,
            'alerts_email' => $data['alerts_email'] ?? true, 'alerts_sms' => $data['alerts_sms'] ?? false];
        if ($existing === null) {
            return (int) $this->db()->table('locum_profiles')->insertGetId($row + ['user_id' => $user->id, 'status' => 'pending', 'created_at' => now()]);
        }
        // A changed registration number needs verifying again.
        if ($existing->hpcsa_number !== $row['hpcsa_number']) {
            $row += ['status' => 'pending', 'verified_at' => null, 'verified_by' => null];
        }
        $this->db()->table('locum_profiles')->where('id', $existing->id)->update($row);

        return (int) $existing->id;
    }

    public function uploadDocument(int $profileId, string $kind, UploadedFile $file, ?string $expiresOn): void
    {
        if (! in_array($kind, ['hpcsa', 'indemnity', 'cv'], true)) {
            throw ValidationException::withMessages(['kind' => 'Choose the document type.']);
        }
        if (in_array($kind, self::REQUIRED_DOCUMENTS, true) && ($expiresOn === null || CarbonImmutable::parse($expiresOn)->isPast())) {
            throw ValidationException::withMessages(['expires_on' => 'Enter the date this document is valid until (in the future).']);
        }
        if (! in_array($file->getMimeType(), ['application/pdf', 'image/jpeg', 'image/png'], true) || $file->getSize() > 10 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'Upload a PDF, JPG or PNG up to 10 MB.']);
        }
        $path = $file->store("locums/{$profileId}", 'local');
        $this->db()->table('locum_documents')->insert(['locum_profile_id' => $profileId, 'kind' => $kind, 'path' => (string) $path,
            'filename' => mb_substr($file->getClientOriginalName(), 0, 200), 'expires_on' => $expiresOn, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function review(int $profileId, bool $approve, int $adminId, ?string $note): void
    {
        if ($approve) {
            foreach (self::REQUIRED_DOCUMENTS as $kind) {
                if (! $this->hasValidDocument($profileId, $kind, CarbonImmutable::today())) {
                    throw ValidationException::withMessages(['profile' => "A current {$kind} document is required before verifying."]);
                }
            }
        }
        $this->db()->table('locum_profiles')->where('id', $profileId)->update([
            'status' => $approve ? 'verified' : 'rejected', 'verified_by' => $approve ? $adminId : null, 'verified_at' => $approve ? now() : null,
            'review_note' => $note, 'updated_at' => now(),
        ]);
    }

    /**
     * Verified, with registration and indemnity valid on the shift date.
     */
    public function eligible(int $profileId, CarbonImmutable $on): bool
    {
        if ($this->db()->table('locum_profiles')->where('id', $profileId)->value('status') !== 'verified') {
            return false;
        }
        foreach (self::REQUIRED_DOCUMENTS as $kind) {
            if (! $this->hasValidDocument($profileId, $kind, $on)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{title: string, starts_at: string, ends_at: string, rate_cents: int, rate_basis: string, requirements: ?string, branch_id: ?int, invited_profile_id: ?int, area?: ?string}  $data
     */
    public function postShift(Provider $provider, array $data, int $by): int
    {
        $start = CarbonImmutable::parse($data['starts_at']);
        $end = CarbonImmutable::parse($data['ends_at']);
        if ($start->isPast() || $end->lte($start) || $start->diffInHours($end) > 24) {
            throw ValidationException::withMessages(['starts_at' => 'A shift must start in the future and last up to 24 hours.']);
        }

        return (int) $this->db()->table('locum_shifts')->insertGetId([
            'tenant_id' => $provider->id, 'branch_id' => $data['branch_id'], 'title' => trim($data['title']), 'area' => isset($data['area']) && trim((string) $data['area']) !== '' ? trim((string) $data['area']) : null, 'starts_at' => $start, 'ends_at' => $end,
            'rate_cents' => $data['rate_cents'], 'rate_basis' => $data['rate_basis'] === 'shift' ? 'shift' : 'hour', 'requirements' => $data['requirements'],
            'invited_profile_id' => $data['invited_profile_id'], 'status' => 'open', 'created_by' => $by, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function apply(int $shiftId, int $profileId, ?string $message): void
    {
        $shift = $this->db()->table('locum_shifts')->where('id', $shiftId)->first();
        if ($shift === null || $shift->status !== 'open' || CarbonImmutable::parse((string) $shift->starts_at)->isPast()) {
            throw ValidationException::withMessages(['shift' => 'This shift is no longer open.']);
        }
        if ($shift->invited_profile_id !== null && (int) $shift->invited_profile_id !== $profileId) {
            throw ValidationException::withMessages(['shift' => 'This shift was offered to a specific locum.']);
        }
        if (! $this->eligible($profileId, CarbonImmutable::parse((string) $shift->ends_at))) {
            throw ValidationException::withMessages(['shift' => 'Your profile must be verified, with registration and indemnity valid on the shift date.']);
        }
        if ($this->overlapsBookedShift($profileId, (string) $shift->starts_at, (string) $shift->ends_at)) {
            throw ValidationException::withMessages(['shift' => 'You already have a booked shift at that time.']);
        }
        $this->db()->table('locum_applications')->insertOrIgnore(['locum_shift_id' => $shiftId, 'locum_profile_id' => $profileId, 'status' => 'applied',
            'message' => $message === null ? null : mb_substr(trim($message), 0, 500), 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * The practice accepts one application: the shift is filled, other applicants are told no,
     * and the locum gets shift-window access plus a roster session. Permanent staff keep their role.
     */
    public function accept(int $applicationId, Provider $provider): void
    {
        $app = $this->db()->table('locum_applications')->where('id', $applicationId)->first();
        $shift = $app === null ? null : $this->db()->table('locum_shifts')->where('id', $app->locum_shift_id)->first();
        if ($app === null || $shift === null || $shift->tenant_id !== $provider->id || $shift->status !== 'open' || $app->status !== 'applied') {
            throw ValidationException::withMessages(['application' => 'This application cannot be accepted.']);
        }
        $end = CarbonImmutable::parse((string) $shift->ends_at);
        if (! $this->eligible((int) $app->locum_profile_id, $end) || $this->overlapsBookedShift((int) $app->locum_profile_id, (string) $shift->starts_at, (string) $shift->ends_at)) {
            throw ValidationException::withMessages(['application' => 'This locum is no longer eligible for the shift.']);
        }
        $user = User::query()->findOrFail((int) $this->db()->table('locum_profiles')->where('id', $app->locum_profile_id)->value('user_id'));

        $this->db()->transaction(function () use ($app, $shift, $provider, $user, $end): void {
            $this->db()->table('locum_applications')->where('id', $app->id)->update(['status' => 'accepted', 'updated_at' => now()]);
            $this->db()->table('locum_applications')->where('locum_shift_id', $shift->id)->where('id', '!=', $app->id)->where('status', 'applied')->update(['status' => 'declined', 'updated_at' => now()]);
            $this->db()->table('locum_shifts')->where('id', $shift->id)->update(['status' => 'filled', 'updated_at' => now()]);

            $existing = Membership::query()->where('user_id', $user->id)->where('tenant_id', $provider->id)->first();
            $permanent = $existing instanceof Membership && $existing->role !== StaffRole::LocumDoctor && $existing->getAttribute('expires_at') === null;
            if (! $permanent) {
                $until = $end->addHours(self::AFTER_HOURS);
                $current = $existing?->getAttribute('expires_at');
                app(AddStaffMember::class)->handle($provider, $user, StaffRole::LocumDoctor, $current !== null && $current > $until ? $current : $until);
            }

            $fee = (int) config('clinicflow.locums.booking_fee_cents', 0);
            if ($fee > 0) {
                $this->db()->table('locum_fees')->insertOrIgnore(['tenant_id' => $provider->id, 'locum_shift_id' => $shift->id, 'amount_cents' => $fee, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $provider->run(fn () => RosterSession::create([
            'staff_id' => $user->id, 'starts_at' => $shift->starts_at, 'ends_at' => $shift->ends_at, 'slot_minutes' => 15, 'branch_id' => $shift->branch_id,
        ]));
        activity('locums')->withProperties(['shift' => $shift->id, 'tenant' => $provider->id, 'user' => $user->id])->log('Locum booked for shift');
    }

    /**
     * Locum doctors from the marketplace may use a practice only inside one of their booked shift windows there.
     */
    public function withinShiftWindow(int $userId, string $tenantId): bool
    {
        $profile = $this->db()->table('locum_profiles')->where('user_id', $userId)->value('id');
        if ($profile === null) {
            return true;
        }
        $now = now();

        return $this->db()->table('locum_shifts')->join('locum_applications', 'locum_applications.locum_shift_id', '=', 'locum_shifts.id')
            ->where('locum_shifts.tenant_id', $tenantId)->where('locum_applications.locum_profile_id', $profile)->where('locum_applications.status', 'accepted')
            ->where('locum_shifts.starts_at', '<=', $now->copy()->addMinutes(self::BEFORE_MINUTES))
            ->where('locum_shifts.ends_at', '>=', $now->copy()->subHours(self::AFTER_HOURS))->exists();
    }

    private function hasValidDocument(int $profileId, string $kind, CarbonImmutable $on): bool
    {
        return $this->db()->table('locum_documents')->where('locum_profile_id', $profileId)->where('kind', $kind)->whereDate('expires_on', '>=', $on->toDateString())->exists();
    }

    private function overlapsBookedShift(int $profileId, string $start, string $end): bool
    {
        return $this->db()->table('locum_shifts')->join('locum_applications', 'locum_applications.locum_shift_id', '=', 'locum_shifts.id')
            ->where('locum_applications.locum_profile_id', $profileId)->where('locum_applications.status', 'accepted')
            ->where('locum_shifts.starts_at', '<', $end)->where('locum_shifts.ends_at', '>', $start)->exists();
    }
}
