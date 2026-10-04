<?php

declare(strict_types=1);

namespace App\Domains\Wellness\Actions;

use App\Domains\Billing\Support\Vat;
use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Platform\Models\Provider;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The employer's side of a wellness day: one invoice per event (screened
 * employees x contracted rate) and an anonymised report. The employer never
 * sees names or individual results; any figure based on fewer than 10 people
 * is withheld; only the listed screening checks can ever appear (sensitive
 * tests such as HIV are never included, even as totals).
 */
class EmployerReporting
{
    public const MIN_GROUP = 10;

    /** The only checks that may ever appear in an employer report. */
    public const REPORTABLE = ['bp', 'glucose', 'cholesterol', 'bmi', 'flu'];

    public const LINK_DAYS = 30;

    public function invoice(int $eventId): int
    {
        $event = DB::table('wellness_events')->where('id', $eventId)->first();
        if ($event === null) {
            throw ValidationException::withMessages(['event' => 'Wellness day not found.']);
        }
        if (DB::table('corporate_invoices')->where('wellness_event_id', $eventId)->exists()) {
            throw ValidationException::withMessages(['event' => 'This wellness day has already been invoiced.']);
        }
        $screened = DB::table('wellness_registrations')->where('wellness_event_id', $eventId)->where('status', 'screened')->count();
        if ($screened === 0) {
            throw ValidationException::withMessages(['event' => 'Nobody has been screened yet.']);
        }
        $rate = (int) DB::table('corporate_accounts')->where('id', $event->corporate_account_id)->value('rate_cents');
        $subtotal = $screened * $rate;
        // The contracted rate excludes VAT; VAT is added on top when the practice is VAT registered.
        $vat = Vat::registered() ? Vat::exclusive($subtotal) : 0;

        return DB::transaction(function () use ($event, $eventId, $screened, $rate, $subtotal, $vat): int {
            $prefix = 'CW-'.now()->format('Y').'-';
            $next = DB::table('corporate_invoices')->where('number', 'like', $prefix.'%')->lockForUpdate()->count() + 1;

            return (int) DB::table('corporate_invoices')->insertGetId([
                'number' => $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT), 'corporate_account_id' => $event->corporate_account_id, 'wellness_event_id' => $eventId,
                'screened' => $screened, 'rate_cents' => $rate, 'subtotal_cents' => $subtotal, 'vat_cents' => $vat, 'total_cents' => $subtotal + $vat, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function markPaid(int $invoiceId, string $reference): void
    {
        if (trim($reference) === '') {
            throw ValidationException::withMessages(['reference' => 'Enter the payment reference.']);
        }
        DB::table('corporate_invoices')->where('id', $invoiceId)->whereNull('paid_at')->update(['paid_at' => now(), 'payment_reference' => trim($reference), 'updated_at' => now()]);
    }

    /**
     * Anonymised totals for one event. Never contains names or individual values.
     *
     * @return array{screened: int, withheld: bool, checks: array<string, array{measured: int, withheld: bool, bands: array<string, int>}>}
     */
    public function summary(int $eventId): array
    {
        $services = array_values(array_intersect(self::REPORTABLE, (array) json_decode((string) DB::table('wellness_events')->where('id', $eventId)->value('services'), true)));
        $rows = DB::table('wellness_screenings')->join('wellness_registrations', 'wellness_registrations.id', '=', 'wellness_screenings.wellness_registration_id')
            ->where('wellness_registrations.wellness_event_id', $eventId)->where('wellness_registrations.status', 'screened')
            ->get(['bp_systolic', 'bmi', 'glucose', 'cholesterol', 'flu_vaccinated', 'flags']);
        $screened = $rows->count();
        $out = ['screened' => $screened, 'withheld' => $screened < self::MIN_GROUP, 'checks' => []];
        if ($out['withheld']) {
            return $out;
        }
        $bands = [
            'bp' => ['field' => 'bp_systolic', 'flags' => ['bp_high' => 'High', 'bp_elevated' => 'Elevated']],
            'glucose' => ['field' => 'glucose', 'flags' => ['glucose_high' => 'High', 'glucose_raised' => 'Raised']],
            'cholesterol' => ['field' => 'cholesterol', 'flags' => ['cholesterol_high' => 'High', 'cholesterol_raised' => 'Raised']],
            'bmi' => ['field' => 'bmi', 'flags' => ['bmi_obese' => 'Obese', 'bmi_overweight' => 'Overweight', 'bmi_underweight' => 'Underweight']],
        ];
        foreach ($services as $service) {
            if ($service === 'flu') {
                $out['checks']['flu'] = ['measured' => $screened, 'withheld' => false, 'bands' => ['Vaccinated' => $rows->where('flu_vaccinated', true)->count()]];

                continue;
            }
            $measured = $rows->whereNotNull($bands[$service]['field']);
            $counts = [];
            foreach ($bands[$service]['flags'] as $flag => $label) {
                $counts[$label] = $measured->filter(fn ($r) => in_array($flag, (array) json_decode((string) $r->flags, true), true))->count();
            }
            $counts['Healthy range'] = $measured->count() - array_sum($counts);
            $withheld = $measured->count() < self::MIN_GROUP;
            $out['checks'][$service] = ['measured' => $measured->count(), 'withheld' => $withheld, 'bands' => $withheld ? [] : $counts];
        }

        return $out;
    }

    /**
     * Creates an expiring link for the employer contact and emails it. Returns the link.
     */
    public function sendToEmployer(int $eventId): string
    {
        $event = DB::table('wellness_events')->join('corporate_accounts', 'corporate_accounts.id', '=', 'wellness_events.corporate_account_id')
            ->where('wellness_events.id', $eventId)->first(['wellness_events.title', 'corporate_accounts.contact_email', 'corporate_accounts.name']);
        if ($event === null || ! filled($event->contact_email)) {
            throw ValidationException::withMessages(['event' => 'Add a contact email to the corporate account first.']);
        }
        $token = Str::random(40);
        DB::table('wellness_report_links')->insert(['wellness_event_id' => $eventId, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(self::LINK_DAYS), 'created_at' => now(), 'updated_at' => now()]);
        // The report lives on the practice's own site, whichever context sends the email.
        $domain = tenant() instanceof Provider ? (string) tenant()->domains()->value('domain') : '';
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
        $link = $domain === '' ? url('/wellness-report/'.$token) : "{$scheme}://{$domain}/wellness-report/{$token}";
        $practice = tenant() instanceof Provider ? (string) tenant()->name : 'Your clinic';
        app(SendMessage::class)->handle('email', (string) $event->contact_email,
            "{$practice} has prepared the anonymised summary and invoice for {$event->title}. This report contains totals only — no names or individual results. Open (valid for ".self::LINK_DAYS." days): {$link}",
            'Wellness day summary: '.$event->title);

        return $link;
    }

    public function eventForToken(string $token): ?int
    {
        $row = DB::table('wellness_report_links')->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first();
        if ($row === null) {
            return null;
        }
        DB::table('wellness_report_links')->where('id', $row->id)->increment('views');

        return (int) $row->wellness_event_id;
    }

    public function reportPdf(int $eventId): string
    {
        $event = DB::table('wellness_events')->join('corporate_accounts', 'corporate_accounts.id', '=', 'wellness_events.corporate_account_id')
            ->where('wellness_events.id', $eventId)->first(['wellness_events.title', 'wellness_events.starts_at', 'wellness_events.location', 'corporate_accounts.name']);
        $sum = $this->summary($eventId);
        $e = fn (string $v) => htmlspecialchars($v, ENT_QUOTES);
        $names = ['bp' => 'Blood pressure', 'glucose' => 'Blood sugar', 'cholesterol' => 'Cholesterol', 'bmi' => 'Body mass index', 'flu' => 'Flu vaccination'];
        $html = '<html><body style="font-family: DejaVu Sans, sans-serif; font-size: 11px"><h2>Wellness day summary</h2>'
            .'<p>'.$e((string) data_get($event, 'name')).' · '.$e((string) data_get($event, 'title')).' · '.substr((string) data_get($event, 'starts_at'), 0, 10).' · '.$e((string) data_get($event, 'location')).'</p>'
            .'<p><b>Employees screened:</b> '.($sum['withheld'] ? 'fewer than '.self::MIN_GROUP : $sum['screened']).'</p>';
        if ($sum['withheld']) {
            $html .= '<p>Fewer than '.self::MIN_GROUP.' people were screened, so no results are reported, to protect individual privacy.</p>';
        }
        foreach ($sum['checks'] as $check => $c) {
            $html .= '<h3>'.$names[$check].'</h3>';
            if ($c['withheld']) {
                $html .= '<p>Withheld: fewer than '.self::MIN_GROUP.' people had this check.</p>';

                continue;
            }
            foreach ($c['bands'] as $label => $n) {
                $html .= '<p>'.$e($label).': '.round($n * 100 / max(1, $c['measured'])).'%</p>';
            }
        }
        $html .= '<p style="color:#666">Totals only. No names or individual results are shared with the employer. Employees received their own results privately.</p></body></html>';

        return $this->pdf($html);
    }

    public function invoicePdf(int $eventId): string
    {
        $inv = DB::table('corporate_invoices')->where('wellness_event_id', $eventId)->first();
        abort_if($inv === null, 404);
        $acc = DB::table('corporate_accounts')->where('id', $inv->corporate_account_id)->first();
        $provider = tenant();
        $e = fn (?string $v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $money = fn (int $c) => 'R '.number_format($c / 100, 2, '.', ' ');
        $vat = (int) $inv->vat_cents > 0;
        $html = '<html><body style="font-family: DejaVu Sans, sans-serif; font-size: 11px">'
            .'<h2>'.($vat ? 'Tax invoice' : 'Invoice').' '.$e($inv->number).'</h2>'
            .'<p><b>From:</b> '.$e($provider instanceof Provider ? $provider->name : '').($vat ? ' · VAT '.$e(Vat::number()) : '').'</p>'
            .'<p><b>To:</b> '.$e(data_get($acc, 'name')).($acc?->vat_number ? ' · VAT '.$e($acc->vat_number) : '').'<br>'.nl2br($e(data_get($acc, 'billing_address'))).'</p>'
            .'<p>Wellness screening: '.(int) $inv->screened.' employees × '.$money((int) $inv->rate_cents).' = '.$money((int) $inv->subtotal_cents)
            .($vat ? '<br>VAT: '.$money((int) $inv->vat_cents) : '').'<br><b>Total: '.$money((int) $inv->total_cents).'</b></p>'
            .($inv->paid_at !== null ? '<p>Paid '.substr((string) $inv->paid_at, 0, 10).' · '.$e($inv->payment_reference).'</p>' : '')
            .'</body></html>';

        return $this->pdf($html);
    }

    private function pdf(string $html): string
    {
        $pdf = new Dompdf;
        $pdf->loadHtml($html);
        $pdf->render();

        return (string) $pdf->output();
    }
}
