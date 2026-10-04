<?php

declare(strict_types=1);

namespace App\Domains\Api\Webhooks;

/**
 * Only public HTTPS addresses may receive webhooks: never localhost, private,
 * reserved or link-local networks (blocks server-side request forgery).
 * Checked when an endpoint is added and again before every delivery.
 */
class WebhookUrlGuard
{
    public function problem(string $url): ?string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return 'Use an https:// address without a username or password.';
        }
        $host = strtolower(trim((string) $parts['host'], '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal') || str_ends_with($host, '.local')) {
            return 'Internal addresses are not allowed.';
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolve($host);
        if ($ips === []) {
            return 'That address could not be found.';
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return 'Private or internal network addresses are not allowed.';
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    protected function resolve(string $host): array
    {
        $v4 = gethostbynamel($host) ?: [];
        $v6 = array_column(dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        return array_merge($v4, array_map('strval', $v6));
    }
}
