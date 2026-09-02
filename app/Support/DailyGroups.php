<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Paginates a list by (site, day) groups instead of by individual row.
 *
 * A site that goes down at a 1-minute check interval, or a crawl that flags 40
 * spam URLs, produces a wall of near-identical rows - and one notification each
 * on top of that. Collapsing them into one entry per site per day keeps the page
 * readable while still reaching everything underneath.
 *
 * Paginating the GROUPS (rather than the rows, then grouping what landed on the
 * page) is what makes the count honest: no group is ever split across two pages.
 */
class DailyGroups
{
    /**
     * @param  Closure():(Builder|\Illuminate\Database\Query\Builder)  $query
     *                                                                         a fresh, already-filtered query - called twice (once for the group keys, once for their rows)
     * @param  Closure(mixed):string  $rowKey  maps a loaded row back to its group key - build it with static::key()
     * @param  string  $siteKeyExpression  raw SQL selecting the site identity (a JSON path for notifications)
     * @param  string  $siteFilterColumn  the same identity as a column path usable in a where()
     * @return array{groups: LengthAwarePaginator, rows: Collection<string, Collection>}
     */
    public static function forPage(
        Closure $query,
        string $dateColumn,
        Closure $rowKey,
        string $siteKeyExpression = 'website_id',
        string $siteFilterColumn = 'website_id',
        int $perPage = 10,
    ): array {
        $groups = $query()
            // reorder() first: a relation's own default ordering (notifications sort
            // by created_at desc) would otherwise reference a column that isn't in
            // GROUP BY, which MySQL rejects under ONLY_FULL_GROUP_BY.
            ->reorder()
            ->selectRaw("{$siteKeyExpression} as site_key, date({$dateColumn}) as day, count(*) as total, max({$dateColumn}) as latest_at")
            ->groupByRaw("{$siteKeyExpression}, date({$dateColumn})")
            ->orderByRaw("max({$dateColumn}) desc")
            ->paginate($perPage);

        if ($groups->isEmpty()) {
            return ['groups' => $groups, 'rows' => collect()];
        }

        $rows = $query()
            ->where(function ($builder) use ($groups, $dateColumn, $siteFilterColumn) {
                foreach ($groups as $group) {
                    // Cast numeric keys back to int: json_extract() hands back a
                    // string, and SQLite compares '61' = 61 as false.
                    $siteKey = is_numeric($group->site_key) ? (int) $group->site_key : $group->site_key;

                    $builder->orWhere(fn ($sub) => $sub
                        ->where($siteFilterColumn, $siteKey)
                        ->whereDate($dateColumn, $group->day));
                }
            })
            ->reorder()
            // created_at breaks ties so two rows stamped the same second still
            // come out newest first rather than in insertion order.
            ->orderByDesc($dateColumn)
            ->orderByDesc('created_at')
            ->get();

        return ['groups' => $groups, 'rows' => $rows->groupBy($rowKey)];
    }

    /**
     * The shared shape of a group key, so the SQL side and the PHP side agree.
     */
    public static function key(int|string|null $siteKey, string $day): string
    {
        return $siteKey.'|'.$day;
    }
}
