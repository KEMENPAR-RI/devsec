<?php

namespace App\Services\Monitoring;

use App\Services\Security\OutboundUrlGuard;
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
     * The record types worth watching, and why:
     *
     * - A / AAAA: where the site is served from. A change here with no deploy
     *   behind it is the shape of a hijack.
     * - NS: who is allowed to answer for the domain at all. Changing this is how
     *   a domain is taken over wholesale, and it is the most serious of them all.
     * - MX: where the institution's mail goes. Attackers repoint this to route
     *   password-reset mail through their own server.
     * - CNAME: who the host is delegated to (CDN, hosting). Repointing it moves
     *   the site without touching A/AAAA at all.
     *
     * A/AAAA/CNAME are read off the host itself; NS/MX off the registrable apex,
     * which is where they live (see APEX_TYPES).
     */
    public const WATCHED = ['A', 'AAAA', 'CNAME', 'NS', 'MX'];

    public const APEX_TYPES = ['NS', 'MX'];

    // Stored instead of anything that points inside a network: no internal-DNS oracle, still diffable.
    public const INTERNAL = '[internal]';

    // Values kept per record type, so a huge answer cannot bloat dns_records or incident history.
    public const MAX_VALUES = 20;

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
        $apex = app(DomainExpiry::class)->registrableDomain($host) ?? strtolower($host);

        foreach (self::WATCHED as $type) {
            $values = $this->query(in_array($type, self::APEX_TYPES, true) ? $apex : $host, $type);

            // A type that returned nothing is omitted rather than stored as an
            // empty list: a transient SERVFAIL would otherwise read as "every MX
            // record was deleted" and open a critical incident for a blip.
            if ($values !== []) {
                $records[$type] = $values;
            }
        }

        // Which name NS/MX were read from, so a baseline taken elsewhere is not compared against it.
        if ($records !== []) {
            $records['apex'] = [$apex];
        }

        return $records;
    }

    /**
     * What actually changed between two answer sets.
     *
     * Only types present in BOTH sides are compared. A type that has appeared or
     * vanished since the baseline is usually the resolver having a bad moment,
     * not the zone being edited, and treating it as a change makes the whole
     * feature cry wolf. NS/MX are skipped when the two sides were read from
     * different names (the "apex" entry), e.g. a baseline from before apex lookups.
     *
     * @param  array<string, array<int, string>>  $before
     * @param  array<string, array<int, string>>  $after
     * @return array<string, array{dari: array<int, string>, ke: array<int, string>}>
     */
    public function diff(array $before, array $after): array
    {
        $changes = [];

        $sameApex = ($before['apex'] ?? null) === ($after['apex'] ?? null);

        foreach (array_intersect(self::WATCHED, array_keys($before), array_keys($after)) as $type) {
            if (! $sameApex && in_array($type, self::APEX_TYPES, true)) {
                continue;
            }

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
     * class of emergency. On a CDN/load-balanced site ($ipRotates) addresses move
     * by design, so A/AAAA drop to warning; NS and CNAME stay critical.
     */
    public function severityFor(array $changes, bool $ipRotates = false): string
    {
        $critical = $ipRotates ? ['NS', 'CNAME'] : ['NS', 'CNAME', 'A', 'AAAA'];

        return array_intersect($critical, array_keys($changes)) ? 'critical' : 'warning';
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
            'CNAME' => DNS_CNAME,
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
                'NS', 'CNAME' => isset($row['target']) ? rtrim(strtolower($row['target']), '.') : null,
                // Priority included: moving the backup MX to the front is a real
                // change even when the same hosts are still listed.
                'MX' => isset($row['target']) ? ($row['pri'] ?? 0).' '.rtrim(strtolower($row['target']), '.') : null,
                default => null,
            })
            ->filter()
            ->all();

        return $this->normalize($type, $values);
    }

    /**
     * Values exactly as stored in dns_records: masked, deduped, sorted, capped.
     * Anything compared against a baseline must go through this too.
     *
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    public function normalize(string $type, array $values): array
    {
        $values = collect($values)
            ->map(fn (string $value) => $this->maskInternal($type, $value))
            ->unique()
            ->values()
            ->all();

        sort($values);

        return array_slice($values, 0, self::MAX_VALUES);
    }

    /** A/AAAA must be public; a CNAME target must resolve publicly to public addresses only. */
    protected function maskInternal(string $type, string $value): string
    {
        return match ($type) {
            'A', 'AAAA' => OutboundUrlGuard::isPublicAddress($value) ? $value : self::INTERNAL,
            'CNAME' => (function () use ($value) {
                [$reason, $addresses] = OutboundUrlGuard::addressesFor($value);

                return $reason === null && $addresses !== [] ? $value : self::INTERNAL;
            })(),
            default => $value,
        };
    }
}
