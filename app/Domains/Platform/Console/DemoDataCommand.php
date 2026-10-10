<?php

declare(strict_types=1);

namespace App\Domains\Platform\Console;

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Membership;
use App\Domains\Patients\Actions\RegisterPatient;
use App\Domains\Patients\Enums\Channel;
use App\Domains\Patients\Enums\ConsentGivenBy;
use App\Domains\Patients\Enums\IdType;
use App\Domains\Patients\Support\RegistrationData;
use App\Domains\Patients\Support\SaIdNumber;
use App\Domains\Platform\Enums\ProviderStatus;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Platform\Support\SubdomainPolicy;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Demo data for trying the platform: a clinic, a pharmacy and a lab on the free packages, with an
 * account for every role. Emails use plus-addressing on your own address (you+demo-clinic-doctor@…),
 * so every sign-in code arrives in your inbox. One generated password is shown once.
 * Remove everything with --remove (practices, their databases and the demo accounts).
 */
class DemoDataCommand extends Command
{
    protected $signature = 'clinicflow:demo {--email= : Your real email; demo accounts use plus-addressing on it} {--remove : Delete the demo practices and accounts}';

    protected $description = 'Create (or remove) demo practices with an account for every role';

    /** @var list<array{0: string, 1: ProviderType, 2: string, 3: string, 4: string}> */
    private const PRACTICES = [
        ['Demo Medical Centre', ProviderType::Clinic, 'demo-clinic', 'clinic-free', 'clinic'],
        ['Demo Pharmacy', ProviderType::Pharmacy, 'demo-pharmacy', 'pharmacy-free', 'pharmacy'],
        ['Demo Lab', ProviderType::Lab, 'demo-lab', 'lab-free', 'lab'],
    ];

    public function handle(AddStaffMember $add): int
    {
        if ($this->option('remove')) {
            return $this->remove();
        }
        $email = strtolower(trim((string) $this->option('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Give your real email: php artisan clinicflow:demo --email=you@example.com');

            return self::FAILURE;
        }
        if (Provider::query()->where('data->demo', true)->exists()) {
            $this->error('Demo practices already exist. Remove them first: php artisan clinicflow:demo --remove');

            return self::FAILURE;
        }
        [$local, $domain] = explode('@', $email, 2);
        $password = Str::password(16, symbols: false);
        $rows = [];
        $phone = 600000000;
        foreach (self::PRACTICES as [$name, $type, $slug, $packageCode, $tag]) {
            $package = Package::query()->where('code', $packageCode)->first();
            if (! $package instanceof Package) {
                $this->error("Package {$packageCode} is missing. Run: php artisan db:seed --force");

                return self::FAILURE;
            }
            $this->line("Creating {$name} (this also creates its database)…");
            $provider = Provider::create(['name' => $name, 'type' => $type, 'status' => ProviderStatus::Active, 'demo' => true]);
            $host = SubdomainPolicy::domainFor($slug);
            $provider->domains()->create(['domain' => $host]);
            Subscription::create(['tenant_id' => $provider->id, 'package_id' => $package->id, 'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addYear()]);
            foreach (StaffRole::forProviderType($type) as $role) {
                $address = "{$local}+demo-{$tag}-".str_replace('_', '-', $role->value)."@{$domain}";
                $user = new User;
                $user->forceFill(['name' => "Demo {$role->label()}", 'email' => $address, 'phone' => '0'.($phone++), 'password' => $password])->save();
                $add->handle($provider, $user, $role);
                $rows[] = [$name, $role->label(), $address, "https://{$host}"];
            }
            if ($type === ProviderType::Clinic) {
                $provider->run(fn () => $this->patients());
            }
        }
        $this->newLine();
        $this->table(['Practice', 'Role', 'Email (sign in with this)', 'Practice address'], $rows);
        $this->warn("Password for every demo account (shown once): {$password}");
        $this->line('Sign in at '.rtrim((string) config('app.url'), '/').'/login. Owners, practice admins and managers set up an authenticator app at first sign-in.');
        $this->line('Practice addresses need the *.'.config('clinicflow.provider_domain').' subdomain on your hosting.');

        return self::SUCCESS;
    }

    private function patients(): void
    {
        $people = [['Thandi', 'Mokoena', '880412'], ['Sipho', 'Dlamini', '750921'], ['Lerato', 'Nkosi', '920305'], ['Pieter', 'van Wyk', '680117'], ['Ayesha', 'Patel', '010622'], ['Johan', 'Botha', '550830']];
        foreach ($people as $i => [$first, $surname, $dob]) {
            app(RegisterPatient::class)->handle(new RegistrationData(
                firstNames: $first, surname: $surname, idType: IdType::SaId, idNumber: $this->saId($dob, $i), passportCountry: null, dateOfBirth: null,
                cell: '07100000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), noCell: false, email: null, preferredLanguage: 'en', preferredChannel: Channel::Sms, address: null,
                guardianName: null, guardianRelationship: null, guardianCell: null, popiaConsent: true, treatmentConsent: true,
                consentGivenBy: ConsentGivenBy::Patient, maturityConfirmed: false, medicalAidScheme: null,
            ));
        }
    }

    private function saId(string $dob, int $i): string
    {
        $partial = $dob.str_pad((string) (5000 + $i), 4, '0', STR_PAD_LEFT).'08';
        for ($check = 0; $check <= 9; $check++) {
            if (SaIdNumber::luhnValid($partial.$check)) {
                return $partial.$check;
            }
        }

        return $partial.'0';
    }

    private function remove(): int
    {
        $providers = Provider::query()->where('data->demo', true)->get();
        $userIds = Membership::query()->whereIn('tenant_id', $providers->pluck('id'))->pluck('user_id')->unique();
        foreach ($providers as $provider) {
            $this->line("Removing {$provider->getAttribute('name')} and its database…");
            $provider->delete();
        }
        // Only demo accounts that no longer belong to any practice.
        $removed = User::query()->whereIn('id', $userIds)->where('email', 'like', '%+demo-%')->where('is_platform_admin', false)
            ->whereNotIn('id', Membership::query()->select('user_id'))->delete();
        $this->info("Removed {$providers->count()} demo practice(s) and {$removed} demo account(s).");

        return self::SUCCESS;
    }
}
