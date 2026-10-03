<?php

declare(strict_types=1);

namespace App\Domains\Platform\Support;

/**
 * Looks up TXT records (swapped for a fake in tests).
 */
class DnsResolver
{
    /**
     * @return list<string>
     */
    public function txt(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT);

        return is_array($records) ? array_map(fn (array $r): string => (string) ($r['txt'] ?? ''), array_values($records)) : [];
    }
}
