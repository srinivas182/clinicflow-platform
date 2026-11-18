<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Middleware;

use App\Domains\Platform\Support\AuditRedactor;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every change a super admin makes in Admin (beta T3: "activity log missing" across Accounting,
 * Groups, Calendars, Resellers, AI scribe, Brands, Storage, Status, WhatsApp, Couriers and
 * message wording) is recorded: who, which screen and action, and what was submitted (secrets
 * masked). Settings also record old and new values where they are saved (WalletSettings::put).
 */
class AuditAdminChanges
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethodSafe() || $response->getStatusCode() >= 400 || $this->failedValidation($request)) {
            return $response;
        }
        $route = $request->route();
        $name = is_object($route) ? (string) $route->getName() : '';
        activity('admin')->causedBy($request->user())->withProperties([
            'route' => $name,
            'parameters' => is_object($route) ? array_map(fn ($p) => is_object($p) && method_exists($p, 'getKey') ? $p->getKey() : $p, $route->parameters()) : [],
            'input' => AuditRedactor::input($request->except(array_keys($request->allFiles())) + array_map(fn () => 'file', $request->allFiles())),
            'ip' => $request->ip(),
        ])->log('Admin: '.$this->label($name, $request));

        return $response;
    }

    private function failedValidation(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }
        $errors = $request->session()->get('errors');

        return $errors instanceof ViewErrorBag && $errors->any();
    }

    /** "admin.storage.act" + action=copy → "storage act (copy)". */
    private function label(string $name, Request $request): string
    {
        $label = str_replace(['admin.', '.', '-'], ['', ' ', ' '], $name !== '' ? $name : $request->path());
        $action = $request->route('action');

        return trim($label.(is_string($action) ? " ({$action})" : ''));
    }
}
