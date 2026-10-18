<?php

/*
 * Test seam for DnsMonitor::query(): it calls dns_get_record() unqualified, so a
 * function of that name in its namespace answers instead of the real resolver
 * while FakeDnsRecords::$rows is set. Loaded with require_once before any test runs.
 */

namespace Tests\Support {
    final class FakeDnsRecords
    {
        /** @var array<string, list<array<string, mixed>>>|null "host TYPE" => dns_get_record() rows; null = real resolver */
        public static ?array $rows = null;

        /** @var list<string> "host TYPE" per lookup */
        public static array $queries = [];

        public static function fake(array $rows): void
        {
            self::$rows = array_change_key_case($rows);
            self::$queries = [];
        }

        public static function reset(): void
        {
            self::$rows = null;
            self::$queries = [];
        }
    }
}

namespace App\Services\Monitoring {
    use Tests\Support\FakeDnsRecords;

    if (! function_exists(__NAMESPACE__.'\dns_get_record')) {
        function dns_get_record(string $hostname, int $type = DNS_ANY, &$authoritative_name_servers = null, &$additional_records = null, bool $raw = false): array|false
        {
            if (FakeDnsRecords::$rows === null) {
                return \dns_get_record($hostname, $type, $authoritative_name_servers, $additional_records, $raw);
            }

            $name = match ($type) {
                DNS_A => 'A',
                DNS_AAAA => 'AAAA',
                DNS_CNAME => 'CNAME',
                DNS_NS => 'NS',
                DNS_MX => 'MX',
                default => (string) $type,
            };
            $key = strtolower($hostname).' '.strtolower($name);
            FakeDnsRecords::$queries[] = strtolower($hostname).' '.$name;

            return FakeDnsRecords::$rows[$key] ?? [];
        }
    }
}
