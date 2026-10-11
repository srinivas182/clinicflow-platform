<?php

// CI only: gives the demo clinic the clinic package with the most features, so the phone checks
// also reach pages that a package unlocks.
declare(strict_types=1);
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$clinic = Provider::query()->where('data->demo', true)->get()
    ->first(fn ($p) => $p->type === ProviderType::Clinic);
$package = Package::query()->where('provider_type', ProviderType::Clinic->value)->get()
    ->sortByDesc(fn ($p) => count((array) $p->features))->first();
if ($clinic === null || $package === null) {
    fwrite(STDERR, "Demo clinic or clinic package missing\n");
    exit(1);
}
Subscription::query()->where('tenant_id', $clinic->id)->update(['package_id' => $package->id]);
echo "Demo clinic now on {$package->code}\n";
