<?php

declare(strict_types=1);

namespace App\Domains\Locums\Http\Controllers;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Locums\Actions\LocumMarketplace;
use App\Domains\Platform\Models\Provider;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Locum portal (any signed-in doctor), super-admin verification, and the practice's shifts page.
 */
class LocumController extends Controller
{
    private function db(): ConnectionInterface
    {
        return DB::connection((string) config('tenancy.database.central_connection'));
    }

    // ---------------- locum portal (platform domain) ----------------

    public function portal(Request $request): Response
    {
        $user = $this->user($request);
        $profile = $this->db()->table('locum_profiles')->where('user_id', $user->id)->first();
        $profileId = $profile === null ? null : (int) $profile->id;

        return Inertia::render('Locums/Portal', [
            'profile' => $profile === null ? null : ['hpcsa_number' => $profile->hpcsa_number, 'qualifications' => $profile->qualifications, 'languages' => json_decode((string) $profile->languages, true),
                'areas' => json_decode((string) $profile->areas, true), 'hourly_rate' => $profile->hourly_rate_cents === null ? null : $profile->hourly_rate_cents / 100, 'bio' => $profile->bio,
                'status' => $profile->status, 'note' => $profile->review_note],
            'documents' => $profileId === null ? [] : $this->db()->table('locum_documents')->where('locum_profile_id', $profileId)->orderByDesc('id')->get(['kind', 'filename', 'expires_on']),
            'shifts' => $profileId === null ? [] : $this->db()->table('locum_shifts')->where('status', 'open')->where('starts_at', '>', now())
                ->where(fn ($q) => $q->whereNull('invited_profile_id')->orWhere('invited_profile_id', $profileId))->orderBy('starts_at')->limit(100)->get()
                ->map(fn ($s) => ['id' => $s->id, 'practice' => Provider::query()->whereKey($s->tenant_id)->value('name'), 'title' => $s->title, 'starts' => $s->starts_at, 'ends' => $s->ends_at,
                    'rate' => $s->rate_cents / 100, 'basis' => $s->rate_basis, 'requirements' => $s->requirements, 'invited' => $s->invited_profile_id !== null,
                    'applied' => $this->db()->table('locum_applications')->where('locum_shift_id', $s->id)->where('locum_profile_id', $profileId)->exists()])->values(),
            'applications' => $profileId === null ? [] : $this->db()->table('locum_applications')->join('locum_shifts', 'locum_shifts.id', '=', 'locum_applications.locum_shift_id')
                ->where('locum_applications.locum_profile_id', $profileId)->orderByDesc('locum_shifts.starts_at')->limit(50)->get(['locum_shifts.tenant_id', 'locum_shifts.title', 'locum_shifts.starts_at', 'locum_applications.status'])
                ->map(fn ($a) => ['practice' => Provider::query()->whereKey($a->tenant_id)->value('name'), 'title' => $a->title, 'starts' => $a->starts_at, 'status' => $a->status])->values(),
        ]);
    }

    public function saveProfile(Request $request, LocumMarketplace $market): RedirectResponse
    {
        $data = $request->validate([
            'hpcsa_number' => ['required', 'string', 'max:20'], 'qualifications' => ['required', 'string', 'max:500'], 'languages' => ['required', 'array', 'min:1'], 'languages.*' => ['string', 'max:30'],
            'areas' => ['required', 'array', 'min:1'], 'areas.*' => ['string', 'max:60'], 'hourly_rate' => ['nullable', 'numeric', 'min:0'], 'bio' => ['nullable', 'string', 'max:1000'],
        ]);
        $market->saveProfile($this->user($request), ['hpcsa_number' => $data['hpcsa_number'], 'qualifications' => $data['qualifications'], 'languages' => array_values($data['languages']),
            'areas' => array_values($data['areas']), 'hourly_rate_cents' => isset($data['hourly_rate']) ? (int) round(((float) $data['hourly_rate']) * 100) : null, 'bio' => $data['bio'] ?? null]);

        return back()->with('success', 'Profile saved. Upload your HPCSA registration and indemnity cover for verification.');
    }

    public function uploadDocument(Request $request, LocumMarketplace $market): RedirectResponse
    {
        $request->validate(['kind' => ['required', Rule::in(['hpcsa', 'indemnity', 'cv'])], 'file' => ['required', 'file', 'max:10240'], 'expires_on' => ['nullable', 'date']]);
        $profileId = $this->profileId($request);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $market->uploadDocument($profileId, $request->string('kind')->toString(), $file, $request->input('expires_on'));

        return back()->with('success', 'Document uploaded.');
    }

    public function apply(Request $request, int $shift, LocumMarketplace $market): RedirectResponse
    {
        $market->apply($shift, $this->profileId($request), $request->string('message')->toString() ?: null);

        return back()->with('success', 'Application sent to the practice.');
    }

    // ---------------- super admin ----------------

    public function admin(): Response
    {
        return Inertia::render('Admin/Locums', [
            'profiles' => $this->db()->table('locum_profiles')->join('users', 'users.id', '=', 'locum_profiles.user_id')->orderByRaw("status = 'pending' desc")->orderByDesc('locum_profiles.id')->limit(200)
                ->get(['locum_profiles.*', 'users.name', 'users.email'])->map(fn ($p) => [
                    'id' => $p->id, 'name' => $p->name, 'email' => $p->email, 'hpcsa' => $p->hpcsa_number, 'qualifications' => $p->qualifications, 'status' => $p->status,
                    'documents' => $this->db()->table('locum_documents')->where('locum_profile_id', $p->id)->get(['id', 'kind', 'filename', 'expires_on']),
                ])->values(),
        ]);
    }

    public function review(Request $request, int $profile, LocumMarketplace $market): RedirectResponse
    {
        $data = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:255']]);
        $market->review($profile, (bool) $data['approve'], $this->user($request)->id, $data['note'] ?? null);

        return back()->with('success', $data['approve'] ? 'Locum verified.' : 'Locum not verified.');
    }

    public function document(int $document): HttpResponse
    {
        $doc = $this->db()->table('locum_documents')->where('id', $document)->first();
        abort_if($doc === null || ! Storage::disk('local')->exists((string) $doc->path), 404);
        activity('locums')->withProperties(['document' => $document])->log('Locum document viewed');

        return response((string) Storage::disk('local')->get((string) $doc->path), 200, ['Content-Type' => (string) Storage::disk('local')->mimeType((string) $doc->path), 'Content-Disposition' => 'inline; filename="'.addslashes((string) $doc->filename).'"']);
    }

    // ---------------- practice (provider domain) ----------------

    public function practice(): Response
    {
        $this->authorize(Permission::STAFF_MANAGE);
        $provider = $this->provider();

        return Inertia::render('Locums/Practice', [
            'shifts' => $this->db()->table('locum_shifts')->where('tenant_id', $provider->id)->orderByDesc('starts_at')->limit(100)->get()->map(fn ($s) => [
                'id' => $s->id, 'title' => $s->title, 'starts' => $s->starts_at, 'ends' => $s->ends_at, 'rate' => $s->rate_cents / 100, 'basis' => $s->rate_basis, 'status' => $s->status,
                'applications' => $this->db()->table('locum_applications')->join('locum_profiles', 'locum_profiles.id', '=', 'locum_applications.locum_profile_id')->join('users', 'users.id', '=', 'locum_profiles.user_id')
                    ->where('locum_applications.locum_shift_id', $s->id)->get(['locum_applications.id', 'locum_applications.status', 'locum_applications.message', 'users.name', 'locum_profiles.qualifications', 'locum_profiles.languages', 'locum_profiles.hpcsa_number'])
                    ->map(fn ($a) => ['id' => $a->id, 'status' => $a->status, 'message' => $a->message, 'name' => $a->name, 'qualifications' => $a->qualifications, 'languages' => json_decode((string) $a->languages, true), 'hpcsa' => $a->hpcsa_number])->values(),
            ])->values(),
            'locums' => $this->db()->table('locum_profiles')->join('users', 'users.id', '=', 'locum_profiles.user_id')->where('locum_profiles.status', 'verified')->orderBy('users.name')->limit(200)
                ->get(['locum_profiles.id', 'users.name', 'locum_profiles.qualifications', 'locum_profiles.areas', 'locum_profiles.languages'])
                ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'qualifications' => $l->qualifications, 'areas' => json_decode((string) $l->areas, true), 'languages' => json_decode((string) $l->languages, true)])->values(),
            'branches' => DB::table('branches')->where('active', true)->get(['id', 'name']),
        ]);
    }

    public function postShift(Request $request, LocumMarketplace $market): RedirectResponse
    {
        $this->authorize(Permission::STAFF_MANAGE);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'], 'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date'], 'rate' => ['required', 'numeric', 'min:0'],
            'rate_basis' => ['required', Rule::in(['hour', 'shift'])], 'requirements' => ['nullable', 'string', 'max:1000'], 'branch_id' => ['nullable', 'integer'], 'invited_profile_id' => ['nullable', 'integer'],
        ]);
        $market->postShift($this->provider(), ['title' => $data['title'], 'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'], 'rate_cents' => (int) round(((float) $data['rate']) * 100),
            'rate_basis' => $data['rate_basis'], 'requirements' => $data['requirements'] ?? null, 'branch_id' => isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            'invited_profile_id' => isset($data['invited_profile_id']) ? (int) $data['invited_profile_id'] : null], $this->user($request)->id);

        return back()->with('success', 'Shift posted.');
    }

    public function accept(int $application, LocumMarketplace $market): RedirectResponse
    {
        $this->authorize(Permission::STAFF_MANAGE);
        $market->accept($application, $this->provider());

        return back()->with('success', 'Locum booked. They can sign in for the shift window only.');
    }

    public function cancel(int $shift): RedirectResponse
    {
        $this->authorize(Permission::STAFF_MANAGE);
        $updated = $this->db()->table('locum_shifts')->where('id', $shift)->where('tenant_id', $this->provider()->id)->where('status', 'open')->update(['status' => 'cancelled', 'updated_at' => now()]);
        abort_if($updated === 0, 422, 'Only open shifts can be cancelled here.');

        return back()->with('success', 'Shift cancelled.');
    }

    private function profileId(Request $request): int
    {
        $id = $this->db()->table('locum_profiles')->where('user_id', $this->user($request)->id)->value('id');
        abort_if($id === null, 422, 'Create your locum profile first.');

        return (int) $id;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function provider(): Provider
    {
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);

        return $provider;
    }
}
