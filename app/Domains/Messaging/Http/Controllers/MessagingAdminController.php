<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Http\Controllers;

use App\Domains\Messaging\Enums\MessagingDriver;
use App\Domains\Messaging\Gateways\EmailGateway;
use App\Domains\Messaging\Gateways\SmsGateway;
use App\Domains\Messaging\Models\MessageTemplate;
use App\Domains\Messaging\Models\MessagingProvider;
use App\Domains\Messaging\Support\MessageCatalogue;
use App\Domains\Platform\Models\Package;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            $saved = $row?->credentials ?? [];
            $providers[] = [
                'driver' => $driver->value, 'label' => $driver->label(), 'channel' => $driver->channel(),
                'fields' => array_map(fn (array $f) => [...$f, 'saved' => isset($saved[$f['key']]) && $saved[$f['key']] !== '', 'value' => $f['secret'] ? '' : (string) ($saved[$f['key']] ?? '')], $driver->credentialFields()),
                'senderLabel' => $driver->senderLabel(), 'sender' => $row?->sender, 'mode' => $row?->mode ?? 'test',
                'enabled' => (bool) $row?->enabled, 'isDefault' => (bool) $row?->is_default, 'testRecipients' => implode(', ', $row?->test_recipients ?? []),
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

        $row = MessagingProvider::query()->firstOrNew(['driver' => $d->value], ['channel' => $d->channel()]);
        $credentials = $row->credentials ?? [];
        foreach ($d->credentialFields() as $f) {
            $value = $data['credentials'][$f['key']] ?? null;
            if (is_string($value) && $value !== '') {
                $credentials[$f['key']] = trim($value);
            }
        }

        $row->forceFill([
            'channel' => $d->channel(), 'mode' => $data['mode'], 'enabled' => (bool) $data['enabled'], 'is_default' => (bool) $data['is_default'],
            'credentials' => $credentials, 'sender' => $data['sender'] ?? null,
            'test_recipients' => array_values(array_filter(array_map('trim', explode(',', (string) ($data['test_recipients'] ?? ''))))),
        ])->save();

        if ($row->is_default) {
            MessagingProvider::query()->where('channel', $d->channel())->whereKeyNot($row->id)->update(['is_default' => false]);
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
            ? SmsGateway::send($row, $data['to'], 'Clinic Flow test message.')
            : EmailGateway::send($row, $data['to'], 'Clinic Flow test message', 'This is a test message from Clinic Flow.', 'Clinic Flow', null);

        $row->forceFill(['last_tested_at' => now(), 'last_test_ok' => $result['ok']])->save();

        return back()->with('success', $result['ok'] ? "Test message sent with {$d->label()}." : "Test failed: {$result['error']}");
    }

    public function saveTemplate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string'], 'channel' => ['required', Rule::in(['sms', 'email'])], 'language' => ['required', Rule::in(MessageCatalogue::LANGUAGES)],
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

        MessageTemplate::query()->updateOrCreate(
            ['key' => $data['key'], 'channel' => $data['channel'], 'language' => $data['language']],
            ['subject' => $data['subject'] ?? null, 'body' => $data['body'], 'updated_by' => $request->user()?->getAuthIdentifier()],
        );

        return back()->with('success', 'Default wording saved.');
    }

    public function savePackage(Request $request, Package $package): RedirectResponse
    {
        $data = $request->validate([
            'sms' => ['required', 'integer', 'min:0', 'max:1000000'], 'email' => ['required', 'integer', 'min:0', 'max:1000000'],
            'sms_overage' => ['required', 'numeric', 'min:0', 'max:100'], 'email_overage' => ['required', 'numeric', 'min:0', 'max:100'],
            'message_types' => ['array'], 'message_types.*' => ['string', Rule::in(['msg_booking', 'msg_reminders', 'msg_queue_alerts', 'msg_billing', 'msg_pharmacy', 'msg_recalls'])],
        ]);

        $limits = $package->limits;
        $limits['sms'] = (int) $data['sms'];
        $limits['email'] = (int) $data['email'];
        $limits['sms_overage_cents'] = (int) round(((float) $data['sms_overage']) * 100);
        $limits['email_overage_cents'] = (int) round(((float) $data['email_overage']) * 100);
        $features = array_values(array_merge(array_filter($package->features, fn (string $f) => ! str_starts_with($f, 'msg_')), $data['message_types'] ?? []));

        $package->forceFill(['limits' => $limits, 'features' => $features])->save();
        activity('platform')->causedBy($request->user())->performedOn($package)->log('Package messaging updated');

        return back()->with('success', "{$package->name} messaging saved.");
    }
}
