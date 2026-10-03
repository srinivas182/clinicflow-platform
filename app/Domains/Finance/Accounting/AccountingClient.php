<?php

declare(strict_types=1);

namespace App\Domains\Finance\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OAuth token exchange/refresh and journal posting for Xero, Sage and Zoho Books.
 */
class AccountingClient
{
    /**
     * @return array{access_token: string, refresh_token: ?string, expires_in: int, org_id: ?string}
     */
    public function exchange(AccountingApp $app, string $code, string $redirect): array
    {
        $tokens = $this->token($app, ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirect]);

        return $tokens + ['org_id' => $this->organisation($app, $tokens['access_token'])];
    }

    public function ensureFreshToken(AccountingApp $app, Model $connection): string
    {
        $expires = $connection->getAttribute('token_expires_at');
        if ($expires !== null && $expires->isFuture() && filled($connection->getAttribute('access_token'))) {
            return (string) $connection->getAttribute('access_token');
        }
        $tokens = $this->token($app, ['grant_type' => 'refresh_token', 'refresh_token' => (string) $connection->getAttribute('refresh_token')]);
        $connection->forceFill(['access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'] ?? $connection->getAttribute('refresh_token'), 'token_expires_at' => now()->addSeconds($tokens['expires_in'] - 60)])->save();

        return $tokens['access_token'];
    }

    /**
     * Posts one balanced journal. Lines: account code, debit, credit (cents), description.
     *
     * @param  list<array{account: string, debit: int, credit: int, description: string}>  $lines
     */
    public function postJournal(AccountingApp $app, Model $connection, string $date, string $reference, array $lines): string
    {
        $token = $this->ensureFreshToken($app, $connection);
        $org = (string) $connection->getAttribute('org_id');
        $money = fn (int $c) => round($c / 100, 2);

        $response = match ($app->driver) {
            AccountingDriver::Xero => Http::withToken($token)->withHeaders(['Xero-tenant-id' => $org])->acceptJson()->post('https://api.xero.com/api.xro/2.0/ManualJournals', [
                'ManualJournals' => [['Narration' => $reference, 'Date' => $date, 'Status' => 'POSTED',
                    'JournalLines' => array_map(fn ($l) => ['AccountCode' => $l['account'], 'LineAmount' => $money($l['debit'] - $l['credit']), 'Description' => $l['description']], $lines)]],
            ]),
            AccountingDriver::Sage => Http::withToken($token)->acceptJson()->post('https://api.accounting.sage.com/v3.1/journals', [
                'journal' => ['date' => $date, 'reference' => $reference, 'description' => $reference,
                    'journal_lines' => array_map(fn ($l) => ['ledger_account_id' => $l['account'], 'debit' => $money($l['debit']), 'credit' => $money($l['credit']), 'details' => $l['description']], $lines)],
            ]),
            AccountingDriver::Zoho => Http::withToken($token, 'Zoho-oauthtoken')->acceptJson()->post('https://www.zohoapis.'.($app->region ?: 'com').'/books/v3/journals?organization_id='.urlencode($org), [
                'journal_date' => $date, 'reference_number' => $reference, 'notes' => $reference,
                'line_items' => array_map(fn ($l) => ['account_id' => $l['account'], 'debit_or_credit' => $l['debit'] > 0 ? 'debit' : 'credit', 'amount' => $money(max($l['debit'], $l['credit'])), 'description' => $l['description']], $lines),
            ]),
        };

        if (! $response->successful()) {
            throw new RuntimeException("{$app->driver->label()} refused the journal (HTTP {$response->status()}).");
        }

        return (string) ($response->json('ManualJournals.0.ManualJournalID') ?? $response->json('id') ?? $response->json('journal.journal_id') ?? '');
    }

    /**
     * @param  array<string, string>  $params
     * @return array{access_token: string, refresh_token: ?string, expires_in: int}
     */
    private function token(AccountingApp $app, array $params): array
    {
        $response = Http::asForm()->acceptJson()->post($app->driver->tokenUrl($app->region), $params + ['client_id' => (string) $app->client_id, 'client_secret' => (string) $app->client_secret]);
        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new RuntimeException("{$app->driver->label()} did not issue a token.");
        }

        return ['access_token' => (string) $response->json('access_token'), 'refresh_token' => is_string($response->json('refresh_token')) ? $response->json('refresh_token') : null, 'expires_in' => (int) ($response->json('expires_in') ?? 1800)];
    }

    private function organisation(AccountingApp $app, string $token): ?string
    {
        return match ($app->driver) {
            AccountingDriver::Xero => Http::withToken($token)->acceptJson()->get('https://api.xero.com/connections')->json('0.tenantId'),
            AccountingDriver::Zoho => Http::withToken($token, 'Zoho-oauthtoken')->acceptJson()->get('https://www.zohoapis.'.($app->region ?: 'com').'/books/v3/organizations')->json('organizations.0.organization_id'),
            AccountingDriver::Sage => null,
        };
    }
}
