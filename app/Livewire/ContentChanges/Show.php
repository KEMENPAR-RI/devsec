<?php

namespace App\Livewire\ContentChanges;

use App\Livewire\Concerns\InteractsWithToasts;
use App\Models\AuditLog;
use App\Models\PageChange;
use App\Services\Monitoring\ContentForensics;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Forensic detail of one content-change event. The payload is decoded per
 * render and never kept in a public property (stays out of the Livewire snapshot).
 */
#[Layout('components.layouts.app')]
class Show extends Component
{
    use InteractsWithToasts;

    protected const FILTERS = ['', 'text', 'element', 'hidden'];

    public PageChange $change;

    /** '' | text | element | hidden - applied to the item list in the view. */
    #[Url]
    public string $filter = '';

    public function mount(PageChange $change): void
    {
        $this->authorize('perubahan-konten.view');

        $this->change = $change->load('website:id,uuid,name,url');
        $this->normalizeFilter();
    }

    public function updatedFilter(): void
    {
        $this->normalizeFilter();
    }

    public function delete(): void
    {
        $this->authorize('perubahan-konten.delete');

        $this->change->delete();
        AuditLog::record('perubahan-konten.dihapus', $this->change->url, ['id' => $this->change->id, 'jenis' => $this->change->type]);

        $this->toastSuccess('Riwayat perubahan dihapus.');
        $this->redirectRoute('perubahan-konten', navigate: true);
    }

    /** Unknown values (hand-edited URL) fall back to "all". */
    protected function normalizeFilter(): void
    {
        if (! in_array($this->filter, self::FILTERS, true)) {
            $this->filter = '';
        }
    }

    public function render()
    {
        $this->normalizeFilter();
        $forensic = $this->change->forensic()->first();
        $payload = $forensic?->payload();

        return view('livewire.content-changes.show', [
            'change' => $this->change,
            'detail' => $payload !== null ? app(ContentForensics::class)->forView($payload) : null,
            // legacy | new_page | removed_page | corrupt, null when detail is shown.
            'unavailableReason' => ContentForensics::unavailableReason($this->change, $forensic, $payload),
        ]);
    }
}
