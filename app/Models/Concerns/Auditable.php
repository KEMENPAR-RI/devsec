<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Records create/update/delete on a model as audit lines.
 *
 * Two deliberate silences:
 *
 *  - Nothing is written when no user is signed in. That keeps seeders, migrations
 *    and the monitor's own writes out of a log that answers "who did this", and
 *    is why a scheduled check does not bury the one line that matters.
 *  - Columns in auditIgnored() are skipped, and an update that touched nothing
 *    else records nothing at all. On Website that is the monitor's bookkeeping
 *    (last_status, last_checked_at, ...), which would otherwise flood the log
 *    whenever a check happens to run inside a request.
 */
trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(fn (Model $model) => $model->writeAuditLine('ditambahkan', $model->auditCreatedChanges()));
        static::updated(fn (Model $model) => $model->writeAuditLine('diubah', $model->auditUpdatedChanges()));
        static::deleted(fn (Model $model) => $model->writeAuditLine('dihapus'));
    }

    /** Event prefix, e.g. 'situs' in 'situs.diubah'. Override to match the page name. */
    public function auditEventPrefix(): string
    {
        return Str::kebab(class_basename($this));
    }

    /** What the line is about, shown in the Subjek column. */
    public function auditSubject(): string
    {
        return (string) ($this->name ?? $this->getKey());
    }

    /** Columns whose changes are never worth a line. */
    public function auditIgnored(): array
    {
        return ['id', 'uuid', 'created_at', 'updated_at'];
    }

    protected function writeAuditLine(string $verb, ?array $changes = []): void
    {
        // Deletion always counts; a create/update with nothing auditable does not.
        if (! Auth::check() || ($verb !== 'dihapus' && $changes === [])) {
            return;
        }

        AuditLog::record("{$this->auditEventPrefix()}.{$verb}", $this->auditSubject(), $changes ?? [], $this);
    }

    /** On create, the values it was created with - no "dari", there was nothing before. */
    protected function auditCreatedChanges(): array
    {
        return $this->auditMask(AuditLog::redact(
            collect($this->getAttributes())
                ->except($this->auditIgnored())
                ->all()
        ));
    }

    /** Last pass before writing - for models whose secret is a value, not a column. */
    protected function auditMask(array $changes): array
    {
        return $changes;
    }

    /** On update, only what moved, as ['kolom' => ['dari' => ..., 'ke' => ...]]. */
    protected function auditUpdatedChanges(): array
    {
        $ignored = $this->auditIgnored();
        $after = AuditLog::redact(collect($this->getChanges())->except($ignored)->all());
        $before = AuditLog::redact(collect($this->getOriginal())->only(array_keys($after))->all());

        return $this->auditMask(collect($after)
            ->map(fn ($value, $field) => ['dari' => $before[$field] ?? null, 'ke' => $value])
            ->all());
    }
}
