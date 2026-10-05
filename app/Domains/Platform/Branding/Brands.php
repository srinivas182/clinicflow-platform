<?php

declare(strict_types=1);

namespace App\Domains\Platform\Branding;

use App\Domains\Platform\Models\Provider;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * White-label brands. A brand changes what practices and patients see (name,
 * logo, colours, support details, practice web address); Clinic Flow still bills.
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

    public function assign(string $providerId, ?int $brandId): void
    {
        if ($brandId !== null && ! $this->db()->table('brands')->where('id', $brandId)->exists()) {
            throw ValidationException::withMessages(['brand_id' => 'Choose a brand.']);
        }
        Provider::query()->whereKey($providerId)->update(['brand_id' => $brandId]);
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
