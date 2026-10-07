<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Membership;
use App\Domains\Messaging\Actions\SendMessage;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records each opening of a patient's record. When one person opens many different patients
 * in an hour the owners are alerted (once an hour per person); past a hard limit, further
 * records are refused. Patient data exports always alert the owners.
 */
class TrackRecordAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $patientId = $this->patientId($request);
        if (! $user instanceof User || $patientId === null || tenant() === null) {
            return $next($request);
        }
        $since = now()->subHour();
        $distinct = DB::table('record_views')->where('staff_id', $user->id)->where('created_at', '>=', $since)->distinct()->count('patient_id');
        $alreadySeen = DB::table('record_views')->where('staff_id', $user->id)->where('patient_id', $patientId)->where('created_at', '>=', $since)->exists();
        $limit = (int) config('clinicflow.security.record_views_hard_limit', 300);
        if (! $alreadySeen && $distinct >= $limit) {
            $this->alert($user, "{$user->name} reached the limit of {$limit} different patient records in an hour; further records were refused.", 'limit');

            abort(429, 'You have opened an unusually large number of patient records in the last hour. Please try again later or ask the practice owner.');
        }
        DB::table('record_views')->insert(['staff_id' => $user->id, 'patient_id' => $patientId, 'route' => (string) $request->route()?->getName(), 'created_at' => now()]);
        $threshold = (int) config('clinicflow.security.record_views_alert', 100);
        if (! $alreadySeen && $distinct + 1 >= $threshold) {
            $this->alert($user, "{$user->name} opened ".($distinct + 1).' different patient records in the last hour. If this is not expected, review their access.', 'unusual');
        }
        $response = $next($request);
        if ($request->routeIs('compliance.patient.export') && $response->isSuccessful()) {
            $this->alert($user, "{$user->name} exported a patient's full record (POPIA export) at ".now()->format('Y-m-d H:i').'.', 'export', false);
        }

        return $response;
    }

    private function patientId(Request $request): ?string
    {
        $patient = $request->route('patient');
        if ($patient !== null) {
            return $patient instanceof Model ? (string) $patient->getKey() : (is_scalar($patient) ? (string) $patient : null);
        }
        $visit = $request->route('visit');
        if ($visit !== null) {
            $id = $visit instanceof Model ? $visit->getAttribute('patient_id') : (is_scalar($visit) ? DB::table('visits')->where('id', (string) $visit)->value('patient_id') : null);

            return $id === null ? null : (string) $id;
        }

        return null;
    }

    /** Emails the practice owners and audits it; repeat alerts for the same person are held back for an hour. */
    private function alert(User $user, string $message, string $kind, bool $throttle = true): void
    {
        $key = 'record-access-alert:'.tenant('id').':'.$user->id.':'.$kind;
        if ($throttle && ! Cache::add($key, 1, now()->addHour())) {
            return;
        }
        activity('security')->causedBy($user)->withProperties(['kind' => $kind])->log($message);
        $owners = Membership::query()->where('tenant_id', tenant('id'))->where('role', StaffRole::Owner->value)->usable()->pluck('user_id');
        foreach (User::query()->whereIn('id', $owners)->pluck('email') as $email) {
            app(SendMessage::class)->handle('email', (string) $email, $message, 'Clinic Flow security alert');
        }
    }
}
