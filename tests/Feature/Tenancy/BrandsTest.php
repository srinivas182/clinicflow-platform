<?php

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Platform\Branding\Brands;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Resellers\ResellerProgramme;
use App\Domains\Platform\Support\DnsResolver;
use App\Models\User;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    $this->seed(PackageSeeder::class);
    $this->brands = app(Brands::class);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

function brandData(array $overrides = []): array
{
    return array_merge(['name' => 'Partner Health', 'slug' => 'partnerhealth', 'primary_color' => '#7a1fa2', 'accent_color' => '#4a148c', 'support_email' => 'help@partnerhealth.test',
        'support_phone' => null, 'footer_text' => null, 'powered_by' => true, 'practice_domain' => 'partnerhealth.test', 'reseller_id' => null, 'active' => true], $overrides);
}

it('validates brands and stores a small logo', function (): void {
    expect(fn () => $this->brands->save(null, brandData(['slug' => 'X!'])))->toThrow(ValidationException::class)
        ->and(fn () => $this->brands->save(null, brandData(['primary_color' => 'purple'])))->toThrow(ValidationException::class)
        ->and(fn () => $this->brands->save(null, brandData(['practice_domain' => config('clinicflow.provider_domain')])))->toThrow(ValidationException::class)
        ->and(fn () => $this->brands->save(null, brandData(), UploadedFile::fake()->create('logo.png', 500, 'image/png')))->toThrow(ValidationException::class);

    $id = $this->brands->save(null, brandData(), UploadedFile::fake()->image('logo.png', 120, 40));
    expect(DB::table('brands')->where('id', $id)->value('logo'))->toStartWith('data:image/png;base64,')
        ->and(fn () => $this->brands->save(null, brandData(['name' => 'Copy'])))->toThrow(ValidationException::class);
});

it('puts practices that sign up through a brand link under that brand, its domain and its commission partner', function (): void {
    $reseller = app(ResellerProgramme::class)->create('Partner Health Sales', 'sales@partnerhealth.test', null, 15, 12);
    $this->brands->save(null, brandData(['reseller_id' => $reseller]));

    $this->get('http://localhost/?brand=partnerhealth')->assertCookie(Brands::COOKIE);
    $this->withCookie(Brands::COOKIE, 'partnerhealth')->post('http://localhost/start', [
        'name' => 'Sunrise Medical Centre', 'type' => 'clinic', 'subdomain' => 'sunrise', 'package_id' => Package::query()->where('code', 'clinic-standard')->value('id'),
        'owner_name' => 'Dr Sizwe Mthembu', 'owner_email' => 'sizwe@sunrise.test', 'owner_phone' => '0827001122', 'password' => 'sunrise-2026', 'password_confirmation' => 'sunrise-2026',
        'references' => ['bhf_practice_number' => '0123456', 'owner_hpcsa' => 'MP0654321'], 'accept_terms' => true,
    ])->assertRedirect();

    $provider = Provider::query()->sole();
    expect($provider->getAttribute('brand_id'))->toBe((int) DB::table('brands')->value('id'))
        ->and($provider->domains()->value('domain'))->toBe('sunrise.partnerhealth.test')
        ->and(DB::table('reseller_referrals')->where('tenant_id', $provider->id)->value('reseller_id'))->toBe($reseller);
});

it('shows the brand to the practice and its patients, and Clinic Flow to everyone else', function (): void {
    $brand = $this->brands->save(null, brandData(['powered_by' => false]));
    $clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $other = makeProvider('Northside Clinic', ProviderType::Clinic, 'northside.clinicflow.test');
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    app(AddStaffMember::class)->handle($clinic, $owner, StaffRole::Owner);
    app(AddStaffMember::class)->handle($other, $otherOwner, StaffRole::Owner);

    $admin = User::factory()->create();
    $admin->forceFill(['is_platform_admin' => true])->save();
    $this->actingAs($admin)->post('http://localhost/admin/brands/assign', ['provider_id' => $clinic->id, 'brand_id' => $brand])->assertSessionHasNoErrors();

    $this->actingAs($owner)->get('http://sunrise.clinicflow.test/workspace')->assertOk()
        ->assertInertia(fn ($p) => $p->where('brand.name', 'Partner Health')->where('brand.primary', '#7a1fa2')->where('brand.poweredBy', false));
    $this->actingAs($otherOwner)->get('http://northside.clinicflow.test/workspace')->assertOk()->assertInertia(fn ($p) => $p->where('brand', null));
    $this->actingAs($admin)->get('http://localhost/admin/brands')->assertOk()->assertInertia(fn ($p) => $p->component('Admin/Brands')->where('brands.0.practices', 1));
});

it('uses a brand email address only after its DNS records verify, and an SMS name only once approved', function (): void {
    $brand = $this->brands->save(null, brandData());
    $clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->brands->assign($clinic->id, $brand);
    $clinic = Provider::query()->findOrFail($clinic->id);

    expect(fn () => $this->brands->setEmailFrom($brand, 'someone@gmail.com'))->toThrow(ValidationException::class);
    $records = $this->brands->setEmailFrom($brand, 'care@partnerhealth.test');
    expect($records['ownership']['host'])->toBe('_clinicflow.partnerhealth.test')
        ->and($this->brands->senderFor($clinic)['email'])->toBeNull();

    $dns = new class extends DnsResolver
    {
        /** @var array<string, list<string>> */
        public array $records = [];

        public function txt(string $host): array
        {
            return $this->records[$host] ?? [];
        }
    };
    expect($this->brands->verifyEmail($brand, $dns))->toBeFalse();
    $dns->records = ['_clinicflow.partnerhealth.test' => [$records['ownership']['value']]];
    expect($this->brands->verifyEmail($brand, $dns))->toBeFalse();
    $dns->records['partnerhealth.test'] = ['v=spf1 '.$records['spf'].' ~all'];
    expect($this->brands->verifyEmail($brand, $dns))->toBeTrue()
        ->and($this->brands->senderFor($clinic)['email'])->toBe('care@partnerhealth.test');

    expect(fn () => $this->brands->setSmsSender($brand, 'Way-too-long-name!', true))->toThrow(ValidationException::class);
    $this->brands->setSmsSender($brand, 'PartnerHlth', false);
    expect($this->brands->senderFor($clinic)['sms'])->toBeNull();
    $this->brands->setSmsSender($brand, 'PartnerHlth', true);
    expect($this->brands->senderFor($clinic)['sms'])->toBe('PartnerHlth');
});

it('shows the partner its brand, practices and sign-ups in the partner portal', function (): void {
    $user = User::factory()->create(['email' => 'sales@partnerhealth.test']);
    $reseller = app(ResellerProgramme::class)->create('Partner Health Sales', 'sales@partnerhealth.test', null, 15, 12);
    $brand = $this->brands->save(null, brandData(['reseller_id' => $reseller]));
    $clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->brands->assign($clinic->id, $brand);

    $this->actingAs($user)->get('http://localhost/reseller')->assertOk()->assertInertia(fn ($p) => $p->component('Reseller/Portal')
        ->where('brands.0.name', 'Partner Health')->where('brands.0.practices.0.name', 'Sunrise Medical Centre')->where('brands.0.signupsThisMonth', 1));
});
