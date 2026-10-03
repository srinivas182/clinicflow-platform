<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Calendar;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Clinic Flow's registered app with Google or Microsoft (super admin).
 *
 * @property int $id
 * @property string $driver google|microsoft
 * @property bool $offered
 * @property string|null $client_id
 * @property string|null $client_secret
 */
class CalendarApp extends Model
{
    use CentralConnection;

    protected $guarded = ['id'];

    protected $hidden = ['client_secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['offered' => 'boolean', 'client_secret' => 'encrypted'];
    }

    public function authorizeUrl(string $redirect, string $state): string
    {
        $q = fn (array $p) => http_build_query($p);

        return $this->driver === 'google'
            ? 'https://accounts.google.com/o/oauth2/v2/auth?'.$q(['client_id' => $this->client_id, 'redirect_uri' => $redirect, 'response_type' => 'code', 'scope' => 'https://www.googleapis.com/auth/calendar', 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state])
            : 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?'.$q(['client_id' => $this->client_id, 'redirect_uri' => $redirect, 'response_type' => 'code', 'scope' => 'offline_access Calendars.ReadWrite', 'state' => $state]);
    }

    public function tokenUrl(): string
    {
        return $this->driver === 'google' ? 'https://oauth2.googleapis.com/token' : 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
    }
}
