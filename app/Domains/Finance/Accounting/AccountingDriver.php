<?php

declare(strict_types=1);

namespace App\Domains\Finance\Accounting;

/**
 * Supported accounting apps. Endpoints follow each vendor's public OAuth 2.0
 * and journal APIs; confirm with a sandbox/demo company before go-live.
 */
enum AccountingDriver: string
{
    case Xero = 'xero';
    case Sage = 'sage';
    case Zoho = 'zoho';

    public function label(): string
    {
        return match ($this) {
            self::Xero => 'Xero',
            self::Sage => 'Sage Business Cloud Accounting',
            self::Zoho => 'Zoho Books',
        };
    }

    public function authorizeUrl(string $clientId, string $redirect, string $state, ?string $region): string
    {
        $q = fn (array $p) => http_build_query($p);

        return match ($this) {
            self::Xero => 'https://login.xero.com/identity/connect/authorize?'.$q(['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirect, 'scope' => 'offline_access accounting.transactions accounting.settings', 'state' => $state]),
            self::Sage => 'https://www.sageone.com/oauth2/auth/central?filter=apiv3.1&'.$q(['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirect, 'scope' => 'full_access', 'state' => $state]),
            self::Zoho => 'https://accounts.zoho.'.($region ?: 'com').'/oauth/v2/auth?'.$q(['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirect, 'scope' => 'ZohoBooks.fullaccess.all', 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state]),
        };
    }

    public function tokenUrl(?string $region): string
    {
        return match ($this) {
            self::Xero => 'https://identity.xero.com/connect/token',
            self::Sage => 'https://oauth.accounting.sage.com/token',
            self::Zoho => 'https://accounts.zoho.'.($region ?: 'com').'/oauth/v2/token',
        };
    }
}
