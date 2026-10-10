<?php

declare(strict_types=1);

namespace App\Domains\Platform\Branding;

use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Support\DnsResolver;
use App\Domains\Platform\Support\TenantLookupCache;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * White-label brands. A brand changes what practices and patients see (name,
 * logo, colours, support details, practice web address); Dr Business Flow still bills.
 */
class Brands
{
    public const COOKIE = 'cf_brand';

    private function db(): ConnectionInterface
    {
        return DB::connection((string) config('tenancy.database.central_connection'));
    }

    /**
     * @param  array{name: string, slug: string, primary_color: string, accent_color: string, support_email: ?string, support_phone: ?string, footer_text: ?string, powered_by: bool, practice_domain: ?string, reseller_id: ?int, active: bool}  $data
     */
    public function save(?int $id, array $data, ?UploadedFile $logo = null): int
    {
        $slug = strtolower(trim($data['slug']));
        $domain = $data['practice_domain'] === null || trim($data['practice_domain']) === '' ? null : strtolower(trim($data['practice_domain']));
        $errors = [];
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/', $slug) !== 1) {
            $errors['slug'] = 'Use 3–40 lowercase letters, numbers or hyphens.';
        } elseif ($this->db()->table('brands')->where('slug', $slug)->when($id !== null, fn ($q) => $q->where('id', '!=', $id))->exists()) {
            $errors['slug'] = 'That short name is taken.';
        }
        foreach (['primary_color', 'accent_color'] as $c) {
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $data[$c]) !== 1) {
                $errors[$c] = 'Use a colour like #0f7c74.';
            }
        }
        if ($domain !== null && (preg_match('/^(?=.{4,120}$)([a-z0-9-]+\.)+[a-z]{2,}$/', $domain) !== 1 || $domain === config('clinicflow.provider_domain'))) {
            $errors['practice_domain'] = 'Enter a domain the partner owns, e.g. partnerhealth.co.za.';
        } elseif ($domain !== null && $this->db()->table('brands')->where('practice_domain', $domain)->when($id !== null, fn ($q) => $q->where('id', '!=', $id))->exists()) {
            $errors['practice_domain'] = 'Another brand uses that domain.';
        }
        if ($logo !== null && (! in_array($logo->getMimeType(), ['image/png', 'image/jpeg', 'image/svg+xml', 'image/webp'], true) || $logo->getSize() > 200 * 1024)) {
            $errors['logo'] = 'Upload a PNG, JPG, WebP or SVG logo up to 200 KB.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $row = ['name' => trim($data['name']), 'slug' => $slug, 'primary_color' => strtolower($data['primary_color']), 'accent_color' => strtolower($data['accent_color']),
            'support_email' => $data['support_email'], 'support_phone' => $data['support_phone'], 'footer_text' => $data['footer_text'], 'powered_by' => $data['powered_by'],
            'practice_domain' => $domain, 'reseller_id' => $data['reseller_id'], 'active' => $data['active'], 'updated_at' => now()];
        if ($logo !== null) {
            $row['logo'] = 'data:'.$logo->getMimeType().';base64,'.base64_encode((string) file_get_contents($logo->getRealPath()));
        }
        if ($id === null) {
            return (int) $this->db()->table('brands')->insertGetId($row + ['created_at' => now()]);
        }
        $this->db()->table('brands')->where('id', $id)->update($row);

        return $id;
    }

    /**
     * Sets the brand's email "from" address; it is used only after the DNS records are verified.
     *
     * @return array{ownership: array{host: string, value: string}, spf: string}
     */
    public function setEmailFrom(int $brandId, string $email): array
    {
        $email = strtolower(trim($email));
        $brand = $this->db()->table('brands')->where('id', $brandId)->first();
        if ($brand === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['email_from' => 'Enter a valid email address on the brand\'s own domain.']);
        }
        $domain = substr($email, (int) strrpos($email, '@') + 1);
        if (in_array($domain, [config('clinicflow.provider_domain'), 'gmail.com', 'outlook.com', 'yahoo.com'], true)) {
            throw ValidationException::withMessages(['email_from' => 'Use an address on the brand\'s own domain.']);
        }
        $token = 'cf-'.bin2hex(random_bytes(12));
        $this->db()->table('brands')->where('id', $brandId)->update(['email_from' => $email, 'email_token' => $token, 'email_verified_at' => null, 'updated_at' => now()]);

        return $this->emailRecords($brandId);
    }

    /**
     * @return array{ownership: array{host: string, value: string}, spf: string}
     */
    public function emailRecords(int $brandId): array
    {
        $brand = $this->db()->table('brands')->where('id', $brandId)->first();
        $domain = $brand?->email_from === null ? '' : substr((string) $brand->email_from, (int) strrpos((string) $brand->email_from, '@') + 1);

        return ['ownership' => ['host' => '_clinicflow.'.$domain, 'value' => $brand === null ? '' : (string) $brand->email_token], 'spf' => (string) config('clinicflow.email.spf_include', 'include:spf.clinicflow.co.za')];
    }

    /** Checks the ownership TXT record and that the domain's SPF record includes the email supplier. */
    public function verifyEmail(int $brandId, DnsResolver $dns): bool
    {
        $brand = $this->db()->table('brands')->where('id', $brandId)->first();
        if ($brand === null || $brand->email_from === null || $brand->email_token === null) {
            throw ValidationException::withMessages(['email_from' => 'Set the email address first.']);
        }
        $records = $this->emailRecords($brandId);
        $domain = substr((string) $brand->email_from, (int) strrpos((string) $brand->email_from, '@') + 1);
        $owns = in_array($records['ownership']['value'], array_map('trim', $dns->txt($records['ownership']['host'])), true);
        $spf = collect($dns->txt($domain))->contains(fn ($t) => str_starts_with(trim((string) $t), 'v=spf1') && str_contains((string) $t, $records['spf']));
        $ok = $owns && $spf;
        $this->db()->table('brands')->where('id', $brandId)->update(['email_verified_at' => $ok ? now() : null, 'updated_at' => now()]);

        return $ok;
    }

    /** SMS sender name: used only once the super admin confirms it is registered with the SMS supplier. */
    public function setSmsSender(int $brandId, ?string $sender, bool $approved): void
    {
        $sender = $sender === null || trim($sender) === '' ? null : trim($sender);
        if ($sender !== null && preg_match('/^[A-Za-z0-9 ]{3,11}$/', $sender) !== 1) {
            throw ValidationException::withMessages(['sms_sender' => 'Use 3–11 letters, numbers or spaces.']);
        }
        $this->db()->table('brands')->where('id', $brandId)->update(['sms_sender' => $sender, 'sms_sender_approved' => $sender !== null && $approved, 'updated_at' => now()]);
    }

    /**
     * Sender details a practice's messages should use (only verified/approved values).
     *
     * @return array{email: ?string, sms: ?string}
     */
    public function senderFor(?Provider $provider): array
    {
        $id = $provider?->getAttribute('brand_id');
        $brand = $id === null ? null : $this->db()->table('brands')->where('id', (int) $id)->where('active', true)->first();

        return ['email' => $brand !== null && $brand->email_verified_at !== null ? (string) $brand->email_from : null,
            'sms' => $brand !== null && (bool) $brand->sms_sender_approved && $brand->sms_sender !== null ? (string) $brand->sms_sender : null];
    }

    public function assign(string $providerId, ?int $brandId): void
    {
        if ($brandId !== null && ! $this->db()->table('brands')->where('id', $brandId)->exists()) {
            throw ValidationException::withMessages(['brand_id' => 'Choose a brand.']);
        }
        Provider::query()->whereKey($providerId)->update(['brand_id' => $brandId]);
        TenantLookupCache::forget((string) $providerId);
    }

    /** The active brand for a sign-up link's short name. */
    public function bySlug(?string $slug): ?\stdClass
    {
        return $slug === null || $slug === '' ? null : $this->db()->table('brands')->where('slug', strtolower($slug))->where('active', true)->first();
    }

    /**
     * What a page shows: the practice's brand, or the sign-up brand remembered from a brand link.
     *
     * @return array{name: string, logo: ?string, primary: string, accent: string, supportEmail: ?string, supportPhone: ?string, footer: ?string, poweredBy: bool}|null
     */
    public function forDisplay(?Provider $provider, ?string $cookieSlug): ?array
    {
        $brand = null;
        if ($provider instanceof Provider) {
            $id = $provider->getAttribute('brand_id');
            $brand = $id === null ? null : $this->db()->table('brands')->where('id', (int) $id)->where('active', true)->first();
        } else {
            $brand = $this->bySlug($cookieSlug);
        }
        if ($brand === null) {
            return null;
        }

        return ['name' => (string) $brand->name, 'logo' => $brand->logo === null ? null : (string) $brand->logo, 'primary' => (string) $brand->primary_color,
            'accent' => (string) $brand->accent_color, 'supportEmail' => $brand->support_email, 'supportPhone' => $brand->support_phone,
            'footer' => $brand->footer_text, 'poweredBy' => (bool) $brand->powered_by];
    }
}
