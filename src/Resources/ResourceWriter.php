<?php

namespace Aptic\Concorde\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ResourceWriter
{
    private array $temporaryModelsById = [];
    private array $activeTemporaryIds = [];
    private array $referencesByScope = [];

    public function __construct(private ResourceImages $images)
    {
    }

    public function store(array $data, Model $model, array $imageFields = [], int $depth = 0): Model
    {
        if ($depth > 20) {
            $this->invalid('resource', 'Relationships are nested too deeply.');
        }

        $temporaryId = $this->temporaryId($data['id'] ?? null);
        $temporaryKey = get_class($model) . ':' . $temporaryId;
        if ($temporaryId && isset($this->activeTemporaryIds[$temporaryKey])) {
            $this->invalid('id', 'Cyclic temporary references are not supported.');
        }
        if ($temporaryId) {
            $this->activeTemporaryIds[$temporaryKey] = true;
        }

        $attributes = Arr::only($data, $model->getFillable());
        unset($attributes[$model->getKeyName()]);
        foreach ($imageFields as $field) {
            unset($attributes[$field]);
        }
        if ($model->exists) {
            // Credentials have dedicated actions; a serialized graph cannot reset them.
            unset($attributes['password']);
        } elseif (isset($attributes['password']) && is_string($attributes['password']) && !Hash::isHashed($attributes['password'])) {
            $attributes['password'] = Hash::make($attributes['password']);
        }
        $model->fill($attributes);

        $pending = [];
        foreach (array_keys($model->resourceRelations()) as $field) {
            $mode = $model->resourceRelations()[$field];
            $relation = $model->{$field}();
            if (!$relation instanceof Relation) {
                $this->invalid($field, 'The configured relationship is invalid.');
            }
            $hasValue = array_key_exists($field, $data);
            if ($relation instanceof BelongsTo) {
                $foreignKey = $relation->getForeignKeyName();
                if (!$hasValue && $mode === 'reference' && array_key_exists($foreignKey, $data)) {
                    $data[$field] = $data[$foreignKey] === null ? null : ['id' => $data[$foreignKey]];
                    $hasValue = true;
                }
                if ($hasValue) {
                    $this->belongsTo($model, $field, $relation, $data[$field], $mode, $depth);
                }
                continue;
            }
            if ($hasValue) {
                $pending[$field] = [$data[$field], $mode];
            }
        }

        if (!$model->exists || $model->isDirty()) {
            $model->saveOrFail();
        }
        if ($temporaryId) {
            $this->temporaryModelsById[$temporaryKey] = $model;
            unset($this->activeTemporaryIds[$temporaryKey]);
        }

        foreach ($pending as $field => [$value, $mode]) {
            // Rebuild after the parent's insert: the relation now has its generated key.
            $relation = $model->{$field}();
            if ($relation instanceof HasMany && $mode === 'owned') {
                $this->hasMany($model, $field, $relation, $value, $depth);
            } elseif ($relation instanceof HasOne && $mode === 'owned') {
                $this->hasOne($model, $field, $relation, $value, $depth);
            } elseif ($relation instanceof BelongsToMany && $mode === 'reference') {
                $this->belongsToMany($model, $field, $relation, $value);
            } else {
                $this->invalid($field, 'Writing this relationship type is not supported.');
            }
        }

        foreach ($imageFields as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && str_starts_with($data[$field], 'data:')) {
                $this->images->store($model, $field, $data[$field]);
            }
        }

        return $model;
    }

    private function belongsTo(Model $parent, string $field, BelongsTo $relation, mixed $value, string $mode, int $depth): void
    {
        if ($value === null) {
            if ($mode !== 'reference') {
                $this->invalid($field, 'An owned relationship cannot be detached.');
            }
            $relation->dissociate();
            return;
        }
        $row = $this->row($field, $value);
        if ($mode === 'reference') {
            $id = $row['id'] ?? null;
            $this->requireId($field, $id);
            $query = $this->referenceQuery($field, $relation);
            $scope = $this->referenceScope($query);
            if (!array_key_exists($id, $this->referencesByScope[$scope] ?? [])) {
                $this->referencesByScope[$scope][$id] = $query->whereKey($id)->first();
            }
            $related = $this->referencesByScope[$scope][$id];
            if (!$related) {
                $this->invalid($field, 'The selected record is not available.');
            }
        } else {
            $related = $parent->exists ? $relation->getQuery()->setEagerLoads([])->first() : null;
            $related = $this->ownedModel($field, $row, $relation, $related);
            $related = $this->store($row, $related, [], $depth + 1);
        }
        $relation->associate($related);
    }

    private function hasMany(Model $parent, string $field, HasMany $relation, mixed $value, int $depth): void
    {
        $rows = $this->collection($field, $value);
        $ids = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if ($id !== null) {
                if (!$this->temporaryId($id)) {
                    $this->requireId($field, $id);
                }
                $ids[] = $this->temporaryId($id) ?? (string) (int) $id;
            }
        }
        if (count(array_unique($ids)) !== count($ids)) {
            $this->invalid($field, 'A related record ID cannot appear more than once.');
        }
        $this->primeReferences($relation->getRelated(), $rows);
        // IDs only: do not hydrate the old collection or trigger its default eager loads.
        $oldIds = $relation->getQuery()->toBase()->pluck($relation->getRelated()->getQualifiedKeyName())->all();
        $incomingIds = array_values(array_filter(array_column($rows, 'id'), fn ($id) => !$this->temporaryId($id)));
        $existing = (clone $relation)->whereKey($incomingIds)->setEagerLoads([])->get()->keyBy($relation->getRelated()->getKeyName());
        $keptIds = [];
        foreach ($rows as $row) {
            $related = $this->ownedModel($field, $row, $relation, $existing->get($row['id'] ?? null));
            $foreignKey = $relation->getForeignKeyName();
            $related->{$foreignKey} = $parent->getAttribute($relation->getLocalKeyName());
            unset($row[$foreignKey]);
            $related = $this->store($row, $related, [], $depth + 1);
            $keptIds[] = $related->getKey();
        }
        $removedIds = array_diff($oldIds, $keptIds);
        if ($removedIds) {
            // Hydrate once and retain per-model delete events and soft-delete behavior.
            foreach ((clone $relation)->whereKey($removedIds)->setEagerLoads([])->get() as $removed) {
                $removed->delete();
            }
        }
        $parent->unsetRelation($field);
    }

    private function hasOne(Model $parent, string $field, HasOne $relation, mixed $value, int $depth): void
    {
        $row = $this->row($field, $value);
        $existing = $relation->getQuery()->setEagerLoads([])->first();
        $related = $this->ownedModel($field, $row, $relation, $existing);
        $foreignKey = $relation->getForeignKeyName();
        $related->{$foreignKey} = $parent->getAttribute($relation->getLocalKeyName());
        unset($row[$foreignKey]);
        $this->store($row, $related, [], $depth + 1);
        $parent->unsetRelation($field);
    }

    private function belongsToMany(Model $parent, string $field, BelongsToMany $relation, mixed $value): void
    {
        $rows = $this->collection($field, $value);
        $ids = [];
        foreach ($rows as $row) {
            $this->requireId($field, $row['id'] ?? null);
            $ids[] = $row['id'];
        }
        $ids = array_values(array_unique($ids));
        $available = $this->referenceQuery($field, $relation)->whereKey($ids)->count();
        if ($available !== count($ids)) {
            $this->invalid($field, 'One or more selected records are not available.');
        }
        $relation->sync($ids);
        $parent->unsetRelation($field);
    }

    private function ownedModel(string $field, array $row, Relation $relation, ?Model $existing): Model
    {
        $id = $row['id'] ?? null;
        if ($id !== null && !$this->temporaryId($id)) {
            $this->requireId($field, $id);
            if (!$existing || (string) $existing->getKey() !== (string) $id) {
                $this->invalid($field, 'The related record does not belong to this resource.');
            }
            return $existing;
        }
        $temporaryKey = get_class($relation->getRelated()) . ':' . $id;
        if ($id && isset($this->temporaryModelsById[$temporaryKey])) {
            $related = $this->temporaryModelsById[$temporaryKey];
            if (!$existing || !$existing->is($related)) {
                $this->invalid($field, 'A temporary record cannot be moved between resources.');
            }
            return $related;
        }
        return $existing ?? $relation->getRelated()->newInstance();
    }

    private function collection(string $field, mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $this->invalid($field, 'The relationship must be an array of records.');
        }
        return array_map(fn ($row) => $this->row($field, $row), $value);
    }

    private function primeReferences(Model $model, array $rows): void
    {
        foreach (array_keys($model->resourceRelations()) as $field) {
            if ($model->resourceRelations()[$field] !== 'reference') {
                continue;
            }
            $relation = $model->{$field}();
            if (!$relation instanceof BelongsTo) {
                continue;
            }
            $ids = [];
            foreach ($rows as $row) {
                $reference = isset($row[$field]) ? $this->row($field, $row[$field]) : [];
                $id = $reference['id'] ?? $row[$relation->getForeignKeyName()] ?? null;
                if ($id !== null) {
                    $this->requireId($field, $id);
                    $ids[] = $id;
                }
            }
            if (!$ids) {
                continue;
            }
            $query = $this->referenceQuery($field, $relation);
            $scope = $this->referenceScope($query);
            $missingIds = array_diff($ids, array_keys($this->referencesByScope[$scope] ?? []));
            if (!$missingIds) {
                continue;
            }
            $related = $query->whereKey($missingIds)->get()->keyBy($relation->getRelated()->getKeyName());
            foreach ($missingIds as $id) {
                $this->referencesByScope[$scope][$id] = $related->get($id);
            }
        }
    }

    private function referenceScope(Builder $query): string
    {
        return serialize([$query->getModel()->getConnectionName(), get_class($query->getModel()), $query->toSql(), $query->getBindings()]);
    }

    private function referenceQuery(string $field, Relation $relation): Builder
    {
        $related = $relation->getRelated();
        if (!method_exists($related, 'scopeVisibleTo')) {
            $this->invalid($field, 'The selected resource has no visibility scope.');
        }
        return $related->newQuery()->setEagerLoads([])->visibleTo(Auth::user());
    }

    private function row(string $field, mixed $value): array
    {
        if (!is_array($value)) {
            $this->invalid($field, 'Each related record must be an object.');
        }
        return $value;
    }

    private function temporaryId(mixed $id): ?string
    {
        return is_string($id) && str_starts_with($id, 'NEW_') ? $id : null;
    }

    private function requireId(string $field, mixed $id): void
    {
        if (!(is_int($id) || (is_string($id) && ctype_digit($id))) || (int) $id <= 0) {
            $this->invalid($field, 'A valid existing record ID is required.');
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
