<?php

declare(strict_types=1);

namespace App\Domains\Telemedicine\Http\Controllers;

use App\Domains\Telemedicine\Models\VideoConfig;
use App\Domains\Telemedicine\Support\LiveKit;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super admin: LiveKit Cloud or self-hosted, test or live. One active at a time.
 */
class TelemedicineAdminController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Telemedicine', [
            'configs' => collect(VideoConfig::DRIVERS)->map(function (string $label, string $driver): array {
                $c = VideoConfig::query()->where('driver', $driver)->first();

                return [
                    'driver' => $driver, 'label' => $label, 'mode' => $c->mode ?? 'test', 'url' => $c?->url, 'apiKey' => $c?->api_key,
                    'hasSecret' => filled($c?->api_secret), 'enabled' => (bool) $c?->enabled,
                    'lastTest' => $c?->last_tested_at === null ? null : ['at' => $c->last_tested_at->format('j M H:i'), 'ok' => (bool) $c->last_test_ok],
                ];
            })->values(),
            'webhookUrl' => rtrim((string) config('app.url'), '/').'/api/webhooks/livekit',
        ]);
    }

    public function save(Request $request, string $driver): RedirectResponse
    {
        abort_unless(array_key_exists($driver, VideoConfig::DRIVERS), 404);
        $data = $request->validate([
            'mode' => ['required', Rule::in(['test', 'live'])], 'enabled' => ['required', 'boolean'],
            'url' => ['nullable', 'string', 'max:255', 'regex:#^wss?://#'], 'api_key' => ['nullable', 'string', 'max:120'], 'api_secret' => ['nullable', 'string', 'max:255'],
        ]);

        $config = VideoConfig::query()->firstOrNew(['driver' => $driver]);
        $config->fill(['mode' => $data['mode'], 'url' => $data['url'] ?? $config->url, 'api_key' => $data['api_key'] ?? $config->api_key]);
        if (filled($data['api_secret'] ?? null)) {
            $config->api_secret = trim((string) $data['api_secret']);
        }
        if ($data['enabled'] && ! $config->isComplete()) {
            throw ValidationException::withMessages(['url' => 'Enter the server address, API key and secret before switching this on.']);
        }
        $config->enabled = (bool) $data['enabled'];
        $config->save();

        if ($config->enabled) {
            // Only one active configuration; calls already running finish on their server.
            VideoConfig::query()->whereKeyNot($config->id)->update(['enabled' => false]);
        }
        activity('platform')->causedBy($request->user() instanceof User ? $request->user() : null)->withProperties(['driver' => $driver, 'mode' => $config->mode, 'enabled' => $config->enabled])->log('Video settings saved');

        return back()->with('success', VideoConfig::DRIVERS[$driver].' saved.');
    }

    public function test(string $driver): RedirectResponse
    {
        $config = VideoConfig::query()->where('driver', $driver)->firstOrFail();
        abort_unless($config->isComplete(), 422, 'Enter the server address, API key and secret first.');
        $result = (new LiveKit($config))->testConnection();
        $config->forceFill(['last_tested_at' => now(), 'last_test_ok' => $result['ok']])->save();

        return back()->with($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Connection works: a test room was created and removed.' : 'Connection failed: '.$result['error']);
    }
}
