<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Platform\Security\BotProtection;
use App\Domains\Telemedicine\Models\VideoConfig;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser security headers on every page. The content-security policy (production, or when
 * CLINICFLOW_CSP=true) allows scripts only from this site or carrying this request's nonce, so an
 * injected script cannot run; HSTS keeps browsers on HTTPS.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $csp = $this->cspEnabled();
        $nonce = Str::random(32);
        if ($csp) {
            Vite::useCspNonce($nonce);
        }
        View::share('cspNonce', $nonce);

        $response = $next($request);
        $h = $response->headers;
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $h->set('X-Frame-Options', 'SAMEORIGIN');
        // Camera and microphone only for this site (video consults, AI scribe); nothing else.
        $h->set('Permissions-Policy', 'camera=(self), microphone=(self), geolocation=(), usb=(), payment=(self)');
        $h->set('Cross-Origin-Opener-Policy', 'same-origin');
        if (app()->isProduction()) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        if ($csp) {
            $header = (bool) config('clinicflow.security.csp_report_only', false) ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
            $h->set($header, $this->policy($nonce));
        }

        return $response;
    }

    public function policy(string $nonce): string
    {
        $video = Cache::remember('csp:video-origins', 300, function (): string {
            try {
                $url = VideoConfig::active()?->url;
            } catch (\Throwable) {
                return '';
            }
            $host = $url === null ? null : parse_url($url, PHP_URL_HOST);

            return is_string($host) && $host !== '' ? "wss://{$host} https://{$host}" : '';
        });

        return implode('; ', array_filter([
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'".$this->turnstile(),
            // React sets some inline style attributes; styles cannot run code.
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob: https:",
            "media-src 'self' blob:",
            "font-src 'self' data:",
            trim("connect-src 'self' {$video} ".$this->realtimeOrigin()).$this->turnstile(),
            "frame-src 'self'".$this->turnstile(),
            "frame-ancestors 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            // Payment pages post to the payment gateway.
            "form-action 'self' https:",
            app()->isProduction() ? 'upgrade-insecure-requests' : null,
        ]));
    }

    /** Cloudflare Turnstile's address, only while bot protection is on. */
    private function turnstile(): string
    {
        return BotProtection::enabled() ? ' '.BotProtection::SCRIPT_HOST : '';
    }

    private function realtimeOrigin(): string
    {
        if (config('broadcasting.default') !== 'reverb') {
            return '';
        }
        $host = (string) config('broadcasting.connections.reverb.options.host');
        $port = (int) config('broadcasting.connections.reverb.options.port');
        $tls = (bool) config('broadcasting.connections.reverb.options.useTLS');

        return $host === '' ? '' : ($tls ? 'wss://' : 'ws://').$host.(in_array($port, [80, 443], true) ? '' : ":{$port}");
    }

    private function cspEnabled(): bool
    {
        $setting = config('clinicflow.security.csp');

        return $setting === null ? app()->isProduction() : (bool) $setting;
    }
}
