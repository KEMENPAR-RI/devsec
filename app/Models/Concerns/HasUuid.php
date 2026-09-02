<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Gives a model a public, non-guessable identifier and makes it the one used in
 * URLs and in Livewire action arguments.
 *
 * The auto-increment primary key stays exactly where it is - it's still what
 * foreign keys, joins, and indexes use, which is what keeps them fast. What
 * changes is what leaves the server: /situs/9f1c... instead of /situs/3, and
 * wire:click="delete('9f1c...')" instead of delete(3). That stops the row count
 * of every table being readable from the address bar and stops anyone walking
 * neighbouring records by editing a number.
 */
trait HasUuid
{
    protected static function bootHasUuid(): void
    {
        static::creating(function (self $model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    /** Route-model binding and Livewire's model arguments both resolve by uuid. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
