<?php

declare(strict_types=1);

namespace App\Domains\Portal\Actions;

use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Patients\Models\Patient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Patient portal sign-in on the provider's own address: cell number, then a
 * one-time SMS code. One cell can manage several profiles (the patient and
 * the children or elderly parents they are guardian for).
 */
class PortalSignIn
{
    public function __construct(private readonly SendMessage $messages) {}

    public function start(string $cell): ?string
    {
        $cell = preg_replace('/\D/', '', $cell) ?? '';
        if (! preg_match('/^0\d{9}$/', $cell)) {
            throw ValidationException::withMessages(['cell' => 'Enter a 10-digit cell number starting with 0.']);
        }

        // Same response whether or not the number is registered, so the portal can't be used to find patients.
        if ($this->profiles($cell)->isEmpty()) {
            return null;
        }

        $code = (string) random_int(100000, 999999);
        $id = (string) Str::ulid();
        DB::table('portal_login_challenges')->insert([
            'id' => $id, 'cell' => $cell, 'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->messages->template('portal.sign_in_code', 'sms', $cell, ['code' => $code], 'en', 'portal_login', $id);

        return $id;
    }

    public function verify(?string $challengeId, string $code): string
    {
        $row = $challengeId === null ? null : DB::table('portal_login_challenges')->where('id', $challengeId)->first();

        if ($row === null || $row->consumed_at !== null || now()->greaterThan($row->expires_at) || $row->attempts >= 5) {
            throw ValidationException::withMessages(['code' => 'This code has expired. Ask for a new one.']);
        }
        if (! Hash::check($code, (string) $row->code_hash)) {
            DB::table('portal_login_challenges')->where('id', $challengeId)->increment('attempts');
            throw ValidationException::withMessages(['code' => 'That code is not correct.']);
        }

        DB::table('portal_login_challenges')->where('id', $challengeId)->update(['consumed_at' => now()]);

        return (string) $row->cell;
    }

    /**
     * Profiles this cell number may manage: its own records and those it is guardian for.
     *
     * @return Collection<int, Patient>
     */
    public function profiles(string $cell): Collection
    {
        return Patient::query()->where('cell', $cell)->orWhere('guardian_cell', $cell)->orderBy('date_of_birth')->get();
    }
}
