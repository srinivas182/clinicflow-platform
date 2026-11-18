<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Http\Controllers;

use App\Domains\Messaging\Enums\MessagingDriver;
use App\Domains\Messaging\Gateways\EmailGateway;
use App\Domains\Messaging\Gateways\SmsGateway;
use App\Domains\Messaging\Models\MessageTemplate;
use App\Domains\Messaging\Models\MessagingProvider;
use App\Domains\Messaging\Support\MessageCatalogue;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Platform\Notifications\PracticeNotice;
use App\Domains\Platform\Support\PracticeContacts;
use App\Domains\Translation\Actions\Translations;
use App\Domains\Translation\Models\Language;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super admin: SMS and email suppliers (platform accounts only), default
 * message wording, and each package's allowances and included message types.
 */
class MessagingAdminController extends Controller
{
    public function index(): Response
    {
        $providers = [];
        foreach (MessagingDriver::cases() as $driver) {
            $row = MessagingProvider::query()->where('driver', $driver->value)->first();
            $saved = $row->credentials ?? [];
            $providers[] = [
                'driver' => $driver->value, 'label' => $driver->label(), 'channel' => $driver->channel(),
                'fields' => array_map(fn (array $f) => [...$f, 'saved' => isset($saved[$f['key']]) && $saved[$f['key']] !== '', 'value' => $f['secret'] ? '' : (string) ($saved[$f['key']] ?? '')], $driver->credentialFields()),
                'senderLabel' => $driver->senderLabel(), 'sender' => $row?->sender, 'mode' => $row->mode ?? 'test',
                'enabled' => (bool) $row?->enabled, 'isDefault' => (bool) $row?->is_default, 'testRecipients' => implode(', ', $row->test_recipients ?? []),
                'lastTest' => $row?->last_tested_at?->format('j M H:i'), 'lastTestOk' => $row?->last_test_ok,
            ];
        }

        $templates = [];
        foreach (MessageCatalogue::all() as $key => $entry) {
            foreach ($entry['channels'] as $channel) {
                $rows = MessageTemplate::query()->where(['key' => $key, 'channel' => $channel])->get()->keyBy('language');
                $templates[] = [
                    'key' => $key, 'label' => $entry['label'], 'channel' => $channel, 'placeholders' => $entry['placeholders'], 'feature' => $entry['feature'],
                    'editableByProviders' => $entry['editable'],
                    'languages' => array_map(fn (string $lang) => [
                        'language' => $lang,
                        'subject' => $rows[$lang]->subject ?? ($lang === 'en' && $channel === 'email' ? $entry['subject'] : null),
                        'body' => $rows[$lang]->body ?? ($lang === 'en' ? ($channel === 'email' ? (string) $entry['email'] : $entry['sms']) : ''),
                    ], MessageCatalogue::LANGUAGES),
                ];
            }
        }

        return Inertia::render('Admin/Messaging', [
            'providers' => $providers,
            'templates' => $templates,
            'packages' => Package::query()->orderBy('sort_order')->get()->map(fn (Package $p) => [
                'id' => $p->id, 'name' => $p->name, 'sms' => $p->limit('sms') ?? 0, 'email' => $p->limit('email') ?? 0,
                'smsOverage' => ($p->limit('sms_overage_cents') ?? 35) / 100, 'emailOverage' => ($p->limit('email_overage_cents') ?? 5) / 100,
                'messageTypes' => array_values(array_filter($p->features, fn (string $f) => str_starts_with($f, 'msg_'))),
            ])->values(),
            'messageTypes' => ['msg_booking' => 'Booking confirmations', 'msg_reminders' => 'Booking reminders', 'msg_queue_alerts' => "Queue \"you're next\"", 'msg_billing' => 'Payment links', 'msg_pharmacy' => 'Medicine ready', 'msg_recalls' => 'Recalls (marketing)'],
        ]);
    }

    public function saveProvider(Request $request, string $driver): RedirectResponse
    {
        $d = MessagingDriver::from($driver);
        $data = $request->validate([
            'mode' => ['required', Rule::in(['test', 'live'])], 'enabled' => ['required', 'boolean'], 'is_default' => ['required', 'boolean'],
            'credentials' => ['array'], 'sender' => ['nullable', 'string', 'max:120'], 'test_recipients' => ['nullable', 'string', 'max:500'],
        ]);
        if ($d->channel() === 'email' && filled($data['sender'] ?? null) && ! filter_var($data['sender'], FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['sender' => 'Enter a from address on the platform domain.']);
        }
        // SMS sender (beta #277): a phone number, or an approved alphanumeric sender of up to 11 letters and digits.
        $sender = trim((string) ($data['sender'] ?? ''));
        if ($d->channel() === 'sms' && $sender !== '' && preg_match('/^(\+?\d{6,15}|(?=.*[A-Za-z])[A-Za-z0-9]{1,11})$/', $sender) !== 1) {
            throw ValidationException::withMessages(['sender' => 'Use a phone number or an approved sender name of up to 11 letters and digits (no spaces).']);
        }

        $row = MessagingProvider::query()->firstOrNew(['driver' => $d->value], ['channel' => $d->channel()]);
        $credentials = $row->credentials ?? [];
        foreach ($d->credentialFields() as $f) {
            $value = $data['credentials'][$f['key']] ?? null;
            if (is_string($value) && $value !== '') {
                $credentials[$f['key']] = trim($value);
            }
        }

        $active = (bool) $data['enabled'];
        if ($active && array_diff(array_column($d->credentialFields(), 'key'), array_keys(array_filter($credentials))) !== []) {
            throw ValidationException::withMessages(['credentials' => "Enter all {$d->label()} credentials before switching it on."]);
        }

        // Live sends real messages (beta #278): a test message must have gone through first, with these keys.
        $keysChanged = $credentials !== ($row->credentials ?? []);
        if ($data['mode'] === 'live' && $row->mode !== 'live' && ($keysChanged || $row->last_test_ok !== true)) {
            throw ValidationException::withMessages(['mode' => "Save {$d->label()} in Test mode and send a test message successfully before switching to Live."]);
        }
        if ($keysChanged) {
            $row->forceFill(['last_tested_at' => null, 'last_test_ok' => null]);
        }
        // Only one active supplier per channel: switching one on switches the others off (their keys are kept).
        $row->forceFill([
            'channel' => $d->channel(), 'mode' => $data['mode'], 'enabled' => $active, 'is_default' => $active,
            'credentials' => $credentials, 'sender' => $data['sender'] ?? null,
            'test_recipients' => array_values(array_filter(array_map('trim', explode(',', (string) ($data['test_recipients'] ?? ''))))),
        ])->save();

        if ($active) {
            MessagingProvider::query()->where('channel', $d->channel())->whereKeyNot($row->id)->update(['enabled' => false, 'is_default' => false]);
        }

        activity('platform')->causedBy($request->user())->withProperties(['driver' => $d->value, 'mode' => $row->mode, 'enabled' => $row->enabled])->log('Messaging supplier saved');

        return back()->with('success', "{$d->label()} saved.");
    }

    public function testProvider(Request $request, string $driver): RedirectResponse
    {
        $d = MessagingDriver::from($driver);
        $data = $request->validate(['to' => ['required', 'string', 'max:120']]);
        $row = MessagingProvider::query()->where('driver', $d->value)->firstOrFail();

        $result = $d->channel() === 'sms'
            ? SmsGateway::send($row, $data['to'], 'Dr Business Flow test message.')
            : EmailGateway::send($row, $data['to'], 'Dr Business Flow test message', 'This is a test message from Dr Business Flow.', 'Dr Business Flow', null);

        $row->forceFill(['last_tested_at' => now(), 'last_test_ok' => $result['ok']])->save();

        return back()->with($result['ok'] ? 'success' : 'error', $result['ok'] ? "Test message sent with {$d->label()}." : "Test failed: {$result['error']}");
    }

    public function saveTemplate(Request $request): RedirectResponse
    {
        // English only: translations go through native-speaker review in Admin → Languages.
        $data = $request->validate([
            'key' => ['required', 'string'], 'channel' => ['required', Rule::in(['sms', 'email'])], 'language' => ['required', Rule::in(['en'])],
            'subject' => ['nullable', 'string', 'max:150'], 'body' => ['required', 'string', 'max:2000'],
        ]);
        $entry = MessageCatalogue::get($data['key']);
        if ($entry === null || ! in_array($data['channel'], $entry['channels'], true)) {
            throw ValidationException::withMessages(['key' => 'Unknown message.']);
        }
        $unknown = MessageCatalogue::unknownPlaceholders($data['key'], $data['body'].' '.($data['subject'] ?? ''));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['body' => 'Unknown placeholders: '.implode(', ', $unknown).'.']);
        }
        $missing = MessageCatalogue::missingPlaceholders($data['key'], $data['body'].' '.($data['subject'] ?? ''));
        if ($missing !== []) {
            throw ValidationException::withMessages(['body' => 'This message must include '.implode(', ', array_map(fn (string $p): string => '{{'.$p.'}}', $missing)).'.']);
        }

        MessageTemplate::query()->updateOrCreate(
            ['key' => $data['key'], 'channel' => $data['channel'], 'language' => $data['language']],
            ['subject' => $data['subject'] ?? null, 'body' => $data['body'], 'updated_by' => $request->user()?->getAuthIdentifier()],
        );

        // Translations of this wording are now re-checked against the new English.
        foreach (Language::query()->where('code', '!=', 'en')->pluck('code') as $language) {
            app(Translations::class)->publish((string) $language, $data['key']);
        }

        return back()->with('success', 'Default wording saved. Any translations of it will show in English until a reviewer re-checks them.');
    }

    public function savePackage(Request $request, Package $package): RedirectResponse
    {
        $data = $request->validate([
            'sms' => ['required', 'integer', 'min:0', 'max:1000000'], 'email' => ['required', 'integer', 'min:0', 'max:1000000'],
            // Extra messages are charged (beta #285): above R0.
            'sms_overage' => ['required', 'numeric', 'gt:0', 'max:100'], 'email_overage' => ['required', 'numeric', 'gt:0', 'max:100'],
            'message_types' => ['array'], 'message_types.*' => ['string', Rule::in(['msg_booking', 'msg_reminders', 'msg_queue_alerts', 'msg_billing', 'msg_pharmacy', 'msg_recalls'])],
        ]);

        $limits = $package->limits;
        $limits['sms'] = (int) $data['sms'];
        $limits['email'] = (int) $data['email'];
        $limits['sms_overage_cents'] = (int) round(((float) $data['sms_overage']) * 100);
        $limits['email_overage_cents'] = (int) round(((float) $data['email_overage']) * 100);
        $features = array_values(array_merge(array_filter($package->features, fn (string $f) => ! str_starts_with($f, 'msg_')), $data['message_types'] ?? []));

        $before = ['limits' => $package->limits, 'types' => array_values(array_filter($package->features, fn (string $f) => str_starts_with($f, 'msg_')))];
        $package->forceFill(['limits' => $limits, 'features' => $features])->save();
        activity('platform')->causedBy($request->user())->performedOn($package)->withProperties(['before' => $before])->log('Package messaging updated');
        $told = $this->tellPractices($package, $before['limits'], $limits, $before['types'], array_values(array_map('strval', (array) ($data['message_types'] ?? []))));

        return back()->with('success', "{$package->name} messaging saved.".($told > 0 ? " {$told} practice(s) on this package were emailed the change." : ''));
    }

    /**
     * Practices on the package are told what changed in their messaging (beta #286); the change is
     * also logged in each practice's activity log.
     *
     * @param  array<string, int>  $old
     * @param  array<string, int>  $new
     * @param  list<string>  $oldTypes
     * @param  list<string>  $newTypes
     */
    private function tellPractices(Package $package, array $old, array $new, array $oldTypes, array $newTypes): int
    {
        $names = ['msg_booking' => 'booking confirmations', 'msg_reminders' => 'booking reminders', 'msg_queue_alerts' => 'queue alerts', 'msg_billing' => 'payment links', 'msg_pharmacy' => 'medicine-ready messages', 'msg_recalls' => 'recalls'];
        $lines = [];
        foreach (['sms' => 'SMS', 'email' => 'emails'] as $key => $label) {
            if (($old[$key] ?? null) !== ($new[$key] ?? null)) {
                $lines[] = "Included {$label} per month: ".($old[$key] ?? 0).' → '.($new[$key] ?? 0).'.';
            }
            $o = $old["{$key}_overage_cents"] ?? null;
            $n = $new["{$key}_overage_cents"] ?? null;
            if ($o !== $n) {
                $lines[] = "Price per extra {$key}: R".number_format(((int) $o) / 100, 2).' → R'.number_format(((int) $n) / 100, 2).'.';
            }
        }
        foreach (array_diff($newTypes, $oldTypes) as $t) {
            $lines[] = 'Now included: '.($names[$t] ?? $t).'.';
        }
        foreach (array_diff($oldTypes, $newTypes) as $t) {
            $lines[] = 'No longer included: '.($names[$t] ?? $t).'.';
        }
        if ($lines === []) {
            return 0;
        }
        $tenants = Subscription::query()->where('package_id', $package->id)->whereIn('status', [SubscriptionStatus::Trialing->value, SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value, SubscriptionStatus::ReadOnly->value])->pluck('tenant_id');
        foreach (Provider::query()->whereIn('id', $tenants)->get() as $provider) {
            if (! $provider instanceof Provider) {
                continue;
            }
            Notification::send(PracticeContacts::owners($provider->id), new PracticeNotice("Messaging on your {$package->name} plan has changed", ["The messaging included in {$package->name} has changed for {$provider->name}:", ...$lines],
                'Messaging settings', PracticeContacts::url($provider, '/settings/messaging'), 'package_changed'));
            $provider->run(fn () => activity('settings')->withProperties(['package' => $package->code])->log('Package messaging changed by Dr Business Flow: '.implode(' ', $lines)));
        }

        return $tenants->count();
    }
}
