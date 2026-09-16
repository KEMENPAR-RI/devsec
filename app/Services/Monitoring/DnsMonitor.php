<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads the DNS records that decide where a domain actually points.
 *
 * Split out from the checker so it can be faked wholesale in tests: a unit test
 * must not depend on what the machine's resolver happens to answer today.
 */
class DnsMonitor
{
    /**
     * The four record types worth watching, and why:
     *
     * - A / AAAA: where the site is served from. A change here with no deploy
     *   behind it is the shape of a hijack.
     * - NS: who is allowed to answer for the domain at all. Changing this is how
     *   a domain is taken over wholesale, and it is the most serious of the four.
     * - MX: where the institution's mail goes. Attackers repoint this to route
     *   password-reset mail through their own server.
     */
    public const WATCHED = ['A', 'AAAA', 'NS', 'MX'];

    /**
     * The current answer set for a host, as sorted lists keyed by record type.
     *
     * Sorted because DNS deliberately returns records in a rotating order, and an
     * unsorted comparison would report a "change" on every single lookup.
     *
     * @return array<string, array<int, string>>
     */
    public function lookUp(string $host): array
    {
        $records = [];

        foreach (self::WATCHED as $type) {
            $values = $this->query($host, $type);

            // A type that returned nothing is omitted rather than stored as an
            // empty list: a transient SERVFAIL would otherwise read as "every MX
            // record was deleted" and open a critical incident for a blip.
            if ($values !== []) {
                $records[$type] = $values;
            }
        }

        return $records;
    }

    /**
     * What actually changed between two answer sets.
     *
     * Only types present in BOTH sides are compared. A type that has appeared or
     * vanished since the baseline is usually the resolver having a bad moment,
     * not the zone being edited, and treating it as a change makes the whole
     * feature cry wolf.
     *
     * @param  array<string, array<int, string>>  $before
     * @param  array<string, array<int, string>>  $after
     * @return array<string, array{dari: array<int, string>, ke: array<int, string>}>
     */
    public function diff(array $before, array $after): array
    {
        $changes = [];

        foreach (array_intersect(array_keys($before), array_keys($after)) as $type) {
            if ($before[$type] !== $after[$type]) {
                $changes[$type] = ['dari' => $before[$type], 'ke' => $after[$type]];
            }
        }

        return $changes;
    }

    /**
     * How loud a given set of changes should be.
     *
     * An NS change is critical without qualification: it means the domain is
     * being answered for by somebody new, which is the whole ballgame. An address
     * change is critical too - that is a hijack or an unannounced migration, and
     * both are worth waking someone. A mail change is serious but not the same
     * class of emergency.
     */
    public function severityFor(array $changes): string
    {
        return array_intersect(['NS', 'A', 'AAAA'], array_keys($changes)) ? 'critical' : 'warning';
    }

    /**
     * @return array<int, string>
     */
    protected function query(string $host, string $type): array
    {
        $constant = match ($type) {
            'A' => DNS_A,
            'AAAA' => DNS_AAAA,
            'NS' => DNS_NS,
            'MX' => DNS_MX,
            default => DNS_A,
        };

        try {
            $rows = @dns_get_record($host, $constant) ?: [];
        } catch (Throwable $e) {
            Log::warning("DnsMonitor: gagal membaca {$type} untuk {$host}: {$e->getMessage()}");

            return [];
        }

        $values = collect($rows)
            ->map(fn (array $row) => match ($type) {
                'A' => $row['ip'] ?? null,
                'AAAA' => $row['ipv6'] ?? null,
                'NS' => isset($row['target']) ? rtrim(strtolower($row['target']), '.') : null,
                // Priority included: moving the backup MX to the front is a real
                // change even when the same hosts are still listed.
                'MX' => isset($row['target']) ? ($row['pri'] ?? 0).' '.rtrim(strtolower($row['target']), '.') : null,
                default => null,
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        sort($values);

        return $values;
    }
}
