<?php

namespace App\Services\Security;

use App\Models\User;
use App\Support\DeviceName;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads and revokes a user's live sessions.
 *
 * Only works on the database session driver, which is what this app ships with
 * (config/session.php). On any other driver the sessions live somewhere this has
 * no index into - the file driver writes one opaque file per session with no user
 * id - so the honest answer is "unavailable" rather than a list that is quietly
 * wrong.
 */
class ActiveSessions
{
    public function isSupported(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * Every live session for this user, newest activity first.
     *
     * @return Collection<int, object{id: string, device: string, ip_address: ?string, last_activity: int, is_current: bool}>
     */
    public function forUser(User $user): Collection
    {
        if (! $this->isSupported()) {
            return collect();
        }

        $currentId = session()->getId();

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $row) => (object) [
                'id' => $row->id,
                'device' => DeviceName::fromUserAgent($row->user_agent),
                'ip_address' => $row->ip_address,
                'last_activity' => (int) $row->last_activity,
                'is_current' => $row->id === $currentId,
            ]);
    }

    /**
     * Ends every session for this user except the one making the request.
     *
     * Deleting the row is what actually ends it: the next request carrying that
     * cookie finds nothing behind it and is treated as a guest. Laravel's own
     * logoutOtherDevices() only rewrites a hash that the AuthenticateSession
     * middleware has to be in place to check, which is a quieter thing to get
     * wrong.
     *
     * @return int how many sessions were ended
     */
    public function revokeOthers(User $user): int
    {
        if (! $this->isSupported()) {
            return 0;
        }

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->where('id', '!=', session()->getId())
            ->delete();
    }
}
