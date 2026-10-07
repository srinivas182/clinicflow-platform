<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Actions\StartProviderSignup;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\VerificationType;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Support\SubdomainPolicy;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Self-service provider sign-up (30-day trial, no card).
 */
class SignupController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Onboarding/Start', [
            'packages' => PricingController::packages(),
            'providerDomain' => config('clinicflow.provider_domain'),
            'checks' => collect(ProviderType::cases())->mapWithKeys(fn (ProviderType $t) => [
                $t->value => array_map(fn (VerificationType $v) => ['type' => $v->value, 'label' => $v->label()], VerificationType::requiredFor($t)),
            ]),
        ]);
    }

    public function store(Request $request, StartProviderSignup $action): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::enum(ProviderType::class)],
            'subdomain' => ['required', 'string', 'max:40'],
            'package_id' => ['required', 'integer'],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'max:255'],
            'owner_phone' => ['required', 'regex:/^0\d{9}$/'],
            'password' => ['required', 'confirmed', config('clinicflow.security.check_leaked_passwords', true)
                ? Password::min(10)->letters()->numbers()->uncompromised()
                : Password::min(10)->letters()->numbers()],
            'references' => ['array'],
            'references.*' => ['nullable', 'string', 'max:60'],
            'accept_terms' => ['accepted'],
        ]);

        /** @var array<string, string> $references */
        $references = array_filter($data['references'] ?? [], 'is_string');

        $provider = $action->handle(
            $data['name'],
            ProviderType::from($data['type']),
            $data['subdomain'],
            Package::query()->findOrFail((int) $data['package_id']),
            $data['owner_name'],
            $data['owner_email'],
            $data['owner_phone'],
            $data['password'],
            $references,
        );

        return redirect()->route('signup.done', ['provider' => $provider->id]);
    }

    public function done(string $provider): Response
    {
        $model = Provider::query()->findOrFail($provider);

        return Inertia::render('Onboarding/Done', [
            'name' => $model->name,
            'address' => $model->domains()->value('domain'),
        ]);
    }

    public function suggest(Request $request): JsonResponse
    {
        $slug = SubdomainPolicy::suggest($request->string('name')->toString());

        return response()->json(['subdomain' => $slug, 'valid' => SubdomainPolicy::isValid($slug)]);
    }
}
