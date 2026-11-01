<?php

namespace Aptic\Concorde\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait HasResourceRelations
{
    public function resourceRelations(): array
    {
        return property_exists($this, 'resourceRelations') ? $this->resourceRelations : [];
    }

    public function resourceImportColumns(): array
    {
        return [];
    }

    public function scopeVisibleTo(Builder $query, $user): Builder
    {
        return $query;
    }
}
