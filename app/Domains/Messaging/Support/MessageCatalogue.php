<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Support;

/**
 * Every message the platform can send. Each entry fixes the channels, the
 * placeholders that may be used, who may edit it and which package feature
 * includes it. Security messages are never editable by providers.
 */
final class MessageCatalogue
{
    public const LANGUAGES = ['en', 'zu', 'xh', 'af'];

    /**
     * @return array<string, array{label: string, channels: list<string>, placeholders: list<string>, scope: string, editable: bool, feature: ?string, category: string, sms: string, subject: ?string, email: ?string}>
     */
    public static function all(): array
    {
        return [
            'auth.sign_in_code' => self::entry('Staff sign-in code', ['sms'], ['code'], 'platform', false, null, 'security',
                'Your Clinic Flow sign-in code is {{ code }}. It expires in 5 minutes. Never share it.'),
            'prescribing.signing_pin' => self::entry('Prescription signing PIN', ['sms'], ['code'], 'platform', false, null, 'security',
                'Your Clinic Flow signing PIN is {{ code }}. It expires in 5 minutes. Never share it.'),
            'portal.sign_in_code' => self::entry('Patient portal sign-in code', ['sms'], ['code', 'practice'], 'provider', false, null, 'security',
                '{{ practice }}: your sign-in code is {{ code }}. It expires in 5 minutes. Never share it.'),
            'network.link_code' => self::entry('Network link approval code', ['sms'], ['code', 'practice'], 'provider', false, null, 'security',
                '{{ practice }} wants to link your Clinic Flow records. Approval code {{ code }}. Only share it at {{ practice }}. Expires in 10 minutes.'),
            'wallet.low_balance' => self::entry('Telemedicine wallet low (to provider owner)', ['email'], ['practice', 'balance', 'threshold'], 'platform', false, null, 'service',
                '', 'Telemedicine wallet low: {{ balance }}',
                '{{ practice }}: your telemedicine wallet balance is {{ balance }}, below the {{ threshold }} minimum. Online consult slots are hidden from patients until you top up.'),
            'lab.results_ready' => self::entry('Lab results ready', ['sms', 'email'], ['patient', 'practice'], 'provider', true, null, 'transactional',
                '{{ practice }}: your lab results are ready. Open the Clinic Flow app to view them.', 'Your results are ready',
                "Dear {{ patient }},\n\nYour lab results from {{ practice }} are ready. Sign in to the patient portal to view them.\n\n{{ practice }}"),
            'booking.confirmation' => self::entry('Booking confirmation', ['sms', 'email'], ['patient', 'practice', 'date', 'time', 'doctor'], 'provider', true, 'msg_booking', 'transactional',
                '{{ practice }}: booked for {{ date }} at {{ time }} with {{ doctor }}. Reply or call to change.', 'Your booking is confirmed',
                "Dear {{ patient }},\n\nYour appointment with {{ doctor }} at {{ practice }} is on {{ date }} at {{ time }}.\n\n{{ practice }}"),
            'booking.reminder' => self::entry('Booking reminder', ['sms', 'email'], ['patient', 'practice', 'date', 'time', 'doctor'], 'provider', true, 'msg_reminders', 'transactional',
                'Reminder from {{ practice }}: {{ date }} at {{ time }} with {{ doctor }}.', 'Appointment reminder',
                "Dear {{ patient }},\n\nThis is a reminder of your appointment with {{ doctor }} on {{ date }} at {{ time }}.\n\n{{ practice }}"),
            'queue.next' => self::entry("You're next", ['sms'], ['ticket', 'room', 'practice'], 'provider', true, 'msg_queue_alerts', 'transactional',
                '{{ practice }}: ticket {{ ticket }}, please come to {{ room }}.'),
            'billing.pay_link' => self::entry('Payment link', ['sms', 'email'], ['patient', 'practice', 'amount', 'link'], 'provider', true, 'msg_billing', 'transactional',
                '{{ practice }}: {{ amount }} due. Pay securely: {{ link }}', 'Payment due',
                "Dear {{ patient }},\n\n{{ amount }} is due to {{ practice }}. Pay securely here: {{ link }}\n\n{{ practice }}"),
            'pharmacy.ready' => self::entry('Medicine ready to collect', ['sms'], ['practice', 'code'], 'provider', true, 'msg_pharmacy', 'transactional',
                '{{ practice }}: your medicine is ready. Collection code {{ code }}.'),
            'recall.reminder' => self::entry('Recall / check-up reminder', ['sms', 'email'], ['patient', 'practice', 'reason'], 'provider', true, 'msg_recalls', 'marketing',
                '{{ practice }}: time for your {{ reason }}. Book in the app. Reply STOP to opt out.', 'Time for your check-up',
                "Dear {{ patient }},\n\nIt is time for your {{ reason }} at {{ practice }}. Book in the patient portal.\n\nTo stop these reminders, reply STOP.\n\n{{ practice }}"),
            'feedback.request' => self::entry('Feedback request after a visit', ['sms', 'email'], ['patient', 'practice', 'link'], 'provider', true, null, 'marketing',
                '{{ practice }}: how was your visit? Tell us in 1 minute: {{ link }} Reply STOP to opt out.', 'How was your visit?',
                "Dear {{ patient }},\n\nThank you for visiting {{ practice }}. Please tell us how it went — it takes a minute: {{ link }}\n\nYour feedback goes to the practice only.\n\n{{ practice }}"),
            'subscription.usage_alert' => self::entry('Messaging allowance alert (to provider owner)', ['email'], ['practice', 'percent', 'channel'], 'platform', false, null, 'service',
                '', 'Messaging allowance: {{ percent }}% used',
                '{{ practice }} has used {{ percent }}% of its {{ channel }} allowance this month. Messages above the allowance are added to the next subscription invoice.'),
        ];
    }

    /**
     * @param  list<string>  $channels
     * @param  list<string>  $placeholders
     * @return array{label: string, channels: list<string>, placeholders: list<string>, scope: string, editable: bool, feature: ?string, category: string, sms: string, subject: ?string, email: ?string}
     */
    private static function entry(string $label, array $channels, array $placeholders, string $scope, bool $editable, ?string $feature, string $category, string $sms, ?string $subject = null, ?string $email = null): array
    {
        return compact('label', 'channels', 'placeholders', 'scope', 'editable', 'feature', 'category', 'sms', 'subject', 'email');
    }

    /**
     * @return array{label: string, channels: list<string>, placeholders: list<string>, scope: string, editable: bool, feature: ?string, category: string, sms: string, subject: ?string, email: ?string}|null
     */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Placeholders used in a text that the message does not allow.
     *
     * @return list<string>
     */
    public static function unknownPlaceholders(string $key, string $text): array
    {
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $text, $m);
        $allowed = self::get($key)['placeholders'] ?? [];

        return array_values(array_unique(array_diff($m[1], $allowed)));
    }

    /**
     * @param  array<string, string>  $vars
     */
    public static function render(string $text, array $vars, bool $html = false): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function (array $m) use ($vars, $html): string {
            $value = $vars[$m[1]] ?? '';

            return $html ? htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $value;
        }, $text);
    }
}
