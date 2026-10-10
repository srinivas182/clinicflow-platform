<?php

declare(strict_types=1);

namespace App\Domains\Finance\Accounting;

use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Wallet\Models\WalletTopup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Builds one balanced journal per day and posts it to the connected app.
 * Providers: from the Dr Business Flow ledger. Platform: paid subscription invoices
 * and wallet top-ups. Every ledger account must be mapped to an account code.
 */
class JournalExporter
{
    public function __construct(private readonly AccountingClient $client) {}

    /**
     * @return array<string, array{debit: int, credit: int}>
     */
    public static function providerTotals(string $date): array
    {
        $rows = DB::table('ledger_entries')->whereDate('occurred_at', $date)
            ->selectRaw('account, SUM(debit_cents) as d, SUM(credit_cents) as c')->groupBy('account')->get();
        $totals = [];
        foreach ($rows as $r) {
            $totals[(string) $r->account] = ['debit' => (int) $r->d, 'credit' => (int) $r->c];
        }

        return $totals;
    }

    /**
     * @return array<string, array{debit: int, credit: int}>
     */
    public static function platformTotals(string $date): array
    {
        $t = [];
        $add = function (string $account, int $debit, int $credit) use (&$t): void {
            $t[$account] ??= ['debit' => 0, 'credit' => 0];
            $t[$account]['debit'] += $debit;
            $t[$account]['credit'] += $credit;
        };
        foreach (SubscriptionInvoice::query()->where('status', 'paid')->whereDate('paid_at', $date)->get() as $i) {
            $add('bank', $i->total_cents, 0);
            $add('revenue:subscriptions', 0, $i->total_cents - $i->vat_cents);
            $add('vat:output', 0, $i->vat_cents);
        }
        foreach (WalletTopup::query()->where('status', 'paid')->whereDate('paid_at', $date)->get() as $w) {
            $add('bank', $w->amount_cents + $w->vat_cents, 0);
            $add('revenue:wallet', 0, $w->amount_cents);
            $add('vat:output', 0, $w->vat_cents);
        }

        return $t;
    }

    /**
     * Exports every day not yet exported, up to yesterday. Returns days posted.
     *
     * @param  callable(string): array<string, array{debit: int, credit: int}>  $totals
     */
    public function run(AccountingApp $app, Model $connection, callable $totals): int
    {
        $from = $connection->getAttribute('exported_until') !== null
            ? CarbonImmutable::parse((string) $connection->getAttribute('exported_until'))->addDay()
            : CarbonImmutable::yesterday();
        $map = (array) ($connection->getAttribute('account_map') ?? []);
        $posted = 0;

        try {
            for ($day = $from; $day->lte(CarbonImmutable::yesterday()); $day = $day->addDay()) {
                $date = $day->toDateString();
                $lines = [];
                foreach ($totals($date) as $account => $sum) {
                    if (! isset($map[$account]) || $map[$account] === '') {
                        throw new \RuntimeException("Map the account \"{$account}\" before exporting.");
                    }
                    $lines[] = ['account' => (string) $map[$account], 'debit' => $sum['debit'], 'credit' => $sum['credit'], 'description' => $account];
                }
                if ($lines !== []) {
                    $this->client->postJournal($app, $connection, $date, "Dr Business Flow {$date}", $lines);
                    $posted++;
                }
                $connection->forceFill(['exported_until' => $date, 'last_error' => null])->save();
            }
        } catch (Throwable $e) {
            $connection->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 250)])->save();
        }

        return $posted;
    }
}
