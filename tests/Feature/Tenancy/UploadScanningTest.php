<?php

use App\Domains\Platform\Security\ClamdScanner;
use App\Domains\Platform\Security\VirusScanner;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    $this->scanner = new class implements VirusScanner
    {
        public ?string $verdict = null;

        public bool $down = false;

        public int $calls = 0;

        public function scan(string $path): ?string
        {
            $this->calls++;
            if ($this->down) {
                throw new RuntimeException('connection refused');
            }

            return $this->verdict;
        }
    };
    $this->app->instance(VirusScanner::class, $this->scanner);
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_platform_admin' => true])->save();
    $this->brand = fn () => ['name' => 'Partner Health', 'slug' => 'partnerhealth', 'primary_color' => '#7a1fa2', 'accent_color' => '#4a148c', 'powered_by' => true, 'active' => true,
        'logo' => UploadedFile::fake()->image('logo.png', 120, 40)];
});

it('refuses infected uploads before anything is stored, and accepts clean ones', function (): void {
    config(['clinicflow.security.virus_scan.enabled' => true]);
    $this->scanner->verdict = 'Eicar-Test-Signature';
    $this->actingAs($this->admin)->post('http://localhost/admin/brands', ($this->brand)())->assertStatus(422);
    expect(DB::table('brands')->count())->toBe(0)
        ->and(DB::table('activity_log')->where('description', 'Infected upload refused')->value('properties'))->toContain('Eicar-Test-Signature');

    $this->scanner->verdict = null;
    $this->actingAs($this->admin)->post('http://localhost/admin/brands', ($this->brand)())->assertSessionHasNoErrors();
    expect(DB::table('brands')->value('logo'))->toStartWith('data:image/png');
});

it('refuses uploads when the scanner is down unless set to fail open, and does nothing when scanning is off', function (): void {
    config(['clinicflow.security.virus_scan.enabled' => true]);
    $this->scanner->down = true;
    $this->actingAs($this->admin)->post('http://localhost/admin/brands', ($this->brand)())->assertStatus(503);
    config(['clinicflow.security.virus_scan.fail_closed' => false]);
    $this->actingAs($this->admin)->post('http://localhost/admin/brands', ($this->brand)())->assertSessionHasNoErrors();

    config(['clinicflow.security.virus_scan.enabled' => false]);
    $calls = $this->scanner->calls;
    $this->actingAs($this->admin)->post('http://localhost/admin/brands', array_merge(($this->brand)(), ['slug' => 'other']))->assertSessionHasNoErrors();
    expect($this->scanner->calls)->toBe($calls);
});

it('understands the virus scanner replies', function (): void {
    expect(ClamdScanner::parse("stream: OK\0"))->toBeNull()
        ->and(ClamdScanner::parse("stream: Eicar-Test-Signature FOUND\0"))->toBe('Eicar-Test-Signature')
        ->and(fn () => ClamdScanner::parse('INSTREAM size limit exceeded. ERROR'))->toThrow(RuntimeException::class);
});
