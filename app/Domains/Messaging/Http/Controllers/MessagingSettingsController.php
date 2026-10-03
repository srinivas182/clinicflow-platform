<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Http\Controllers;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Messaging\Models\MessageTemplateOverride;
use App\Domains\Messaging\Models\MessageUsage;
use App\Domains\Messaging\Support\MessageCatalogue;
use App\Domains\Messaging\Support\MessagingUsage;
use App\Domains\Messaging\Support\TemplateResolver;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Setting;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Provider messaging settings: email from-name and reply-to, usage against
 * the package allowance, and wording for the messages the package includes.
 * Suppliers are configured by the super admin only.
 */
class MessagingSettingsController extends Controller
{
    public function index(): Response
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $provider = $this->provider();
        $package = MessagingUsage::packageFor($provider->id);
        $usage = MessageUsage::query()->where('tenant_id', $provider->id)->where('period', now()->format('Y-m'))->first();

        $messages = [];
        foreach (MessageCatalogue::all() as $key => $entry) {
            if ($entry['scope'] !== 'provider') {
                continue;
            }
            $included = $entry['feature'] === null || ($package?->hasFeature($entry['feature']) ?? false);
            foreach ($entry['channels'] as $channel) {
                $current = TemplateResolver::resolve($key, $channel);
                $messages[] = [
                    'key' => $key, 'label' => $entry['label'], 'channel' => $channel, 'included' => $included, 'editable' => $entry['editable'] && $included,
                    'placeholders' => $entry['placeholders'], 'subject' => $current['subject'], 'body' => $current['body'],
                    'customised' => MessageTemplateOverride::query()->where(['key' => $key, 'channel' => $channel, 'language' => 'en'])->exists(),
                ];
            }
        }

        return Inertia::render('Settings/Messaging', [
            'fromName' => (string) Setting::get('messaging', 'from_name', $provider->name),
            'replyTo' => (string) Setting::get('messaging', 'reply_to', ''),
            'usage' => [
                'sms' => (int) ($usage?->getAttribute('sms_units') ?? 0), 'email' => (int) ($usage?->getAttribute('email_units') ?? 0),
                'smsLimit' => $package?->limit('sms') ?? 0, 'emailLimit' => $package?->limit('email') ?? 0,
                'smsOverage' => ($package?->limit('sms_overage_cents') ?? 35) / 100, 'emailOverage' => ($package?->limit('email_overage_cents') ?? 5) / 100,
            ],
            'messages' => $messages,
        ]);
    }

    public function saveSender(Request $request): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $data = $request->validate(['from_name' => ['required', 'string', 'max:60'], 'reply_to' => ['nullable', 'email', 'max:120']]);
        Setting::put('messaging', 'from_name', trim($data['from_name']));
        Setting::put('messaging', 'reply_to', $data['reply_to'] ?? '');

        return back()->with('success', 'Sender details saved.');
    }

    public function saveWording(Request $request): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $data = $request->validate([
            'key' => ['required', 'string'], 'channel' => ['required', Rule::in(['sms', 'email'])], 'language' => ['nullable', Rule::in(MessageCatalogue::LANGUAGES)],
            'subject' => ['nullable', 'string', 'max:150'], 'body' => ['nullable', 'string', 'max:1000'], 'reset' => ['boolean'],
        ]);

        $entry = MessageCatalogue::get($data['key']);
        $package = MessagingUsage::packageFor($this->provider()->id);
        if ($entry === null || $entry['scope'] !== 'provider' || ! $entry['editable'] || ! in_array($data['channel'], $entry['channels'], true)
            || ($entry['feature'] !== null && ! ($package?->hasFeature($entry['feature']) ?? false))) {
            throw ValidationException::withMessages(['key' => 'This message is not editable in your package.']);
        }

        $where = ['key' => $data['key'], 'channel' => $data['channel'], 'language' => $data['language'] ?? 'en'];
        if ($request->boolean('reset')) {
            MessageTemplateOverride::query()->where($where)->delete();

            return back()->with('success', 'Back to the standard wording.');
        }

        $body = trim((string) ($data['body'] ?? ''));
        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Enter the message text.']);
        }
        $unknown = MessageCatalogue::unknownPlaceholders($data['key'], $body.' '.($data['subject'] ?? ''));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['body' => 'Unknown placeholders: '.implode(', ', $unknown).'.']);
        }

        MessageTemplateOverride::query()->updateOrCreate($where, ['subject' => $data['subject'] ?? null, 'body' => $body, 'updated_by' => $request->user()?->getAuthIdentifier()]);
        activity('settings')->causedBy($request->user())->withProperties($where)->log('Message wording changed');

        return back()->with('success', 'Wording saved.');
    }

    private function provider(): Provider
    {
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);

        return $provider;
    }
}
