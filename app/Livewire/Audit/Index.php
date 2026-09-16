<?php

namespace App\Livewire\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only view over audit_logs. There is no action on this page at all - no
 * edit, no delete, no bulk clear - because the value of the trail is that the
 * people it records cannot curate it.
 */
#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    /** '' for all, otherwise the part of an event name before the dot. */
    #[Url]
    public string $group = '';

    /** '' for all, otherwise a user uuid. */
    #[Url]
    public string $actor = '';

    public function mount(): void
    {
        $this->authorize('audit.view');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingGroup(): void
    {
        $this->resetPage();
    }

    public function updatingActor(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'group', 'actor']);
        $this->resetPage();
    }

    public function render()
    {
        $logs = AuditLog::query()
            ->search($this->search)
            // Grouped: without the inner closure the orWhere would escape the
            // search filter and widen the whole query instead of narrowing it.
            ->when($this->group, fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('event', 'like', "{$this->group}.%")
                ->orWhere('event', $this->group)))
            ->when($this->actor, fn (Builder $q) => $q->whereHas('user', fn (Builder $u) => $u->where('uuid', $this->actor)))
            ->latest('created_at')
            ->latest('id')
            ->paginate(25);

        return view('livewire.audit.index', [
            'logs' => $logs,
            'groups' => AuditLog::query()
                ->selectRaw('event')
                ->distinct()
                ->pluck('event')
                ->map(fn (string $event) => Str::before($event, '.'))
                ->unique()
                ->sort()
                ->values(),
            // Only accounts that actually appear in the trail, so the filter has
            // no empty options.
            'actors' => User::query()
                ->whereIn('id', AuditLog::query()->whereNotNull('user_id')->distinct()->pluck('user_id'))
                ->orderBy('name')
                ->get(['id', 'uuid', 'name']),
        ]);
    }
}
