<?php

// CI only: gives the demo clinic the clinic package with the most features, so the phone checks
// also reach pages that a package unlocks.
declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$clinic = App\Domains\Platform\Models\Provider::query()->where('data->demo', true)->get()
    ->first(fn ($p) => $p->type === App\Domains\Platform\Enums\ProviderType::Clinic);
$package = App\Domains\Platform\Models\Package::query()->where('provider_type', App\Domains\Platform\Enums\ProviderType::Clinic->value)->get()
    ->sortByDesc(fn ($p) => count((array) $p->features))->first();
if ($clinic === null || $package === null) {
    fwrite(STDERR, "Demo clinic or clinic package missing\n");
    exit(1);
}
App\Domains\Platform\Models\Subscription::query()->where('tenant_id', $clinic->id)->update(['package_id' => $package->id]);
echo "Demo clinic now on {$package->code}\n";
