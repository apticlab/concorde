<?php

namespace Aptic\Concorde\Http\Controllers;

use Aptic\Concorde\Resources\ResourceCsvImporter;
use Aptic\Concorde\Resources\ResourceImages;
use Aptic\Concorde\Resources\ResourceWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ResourceBaseController extends Controller
{
    public $resourceClass = null;
    public $relatedResources = [];
    public $orderBy = [];

    public function index(Request $request)
    {
        if (!$this->resourceClass) {
            return null;
        }
        if (Gate::getPolicyFor($this->resourceClass)) {
            Gate::authorize('viewAny', $this->resourceClass);
        }
        $params = $request->input();
        $query = $this->indexFilter($this->resourceQuery(), $params);
        $query->with($this->resourceWith('index'));
        $query->withCount($this->withCount['index'] ?? []);
        $this->applyOrdering($query, $this->orderBy);

        if (config('app.debug')) {
            Log::debug('Resource index query', ['resource' => $this->resourceClass, 'sql' => $query->toSql()]);
        }
        $noPaginate = filter_var($params['no_paginate'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (($this->paginate ?? false) && !$noPaginate) {
            return $query->paginate($this->paginationRows ?? 10);
        }
        return $query->get();
    }

    public function indexFilter($query, $params)
    {
        return $query;
    }

    public function preStore($resourceData, $resourceModel)
    {
        return $resourceData;
    }

    public function postStore($resourceData, $resourceModel)
    {
        return $resourceModel;
    }

    public function preUpdate($resourceData, $resourceModel)
    {
        return $resourceData;
    }

    public function postUpdate($resourceData, $resourceModel)
    {
        return $resourceModel;
    }

    public function preDestroy($resourceId)
    {
        return true;
    }

    protected function storeResource(array $data)
    {
        try {
            $model = new $this->resourceClass();
            Gate::authorize('create', $model);
            $images = new ResourceImages();
            $saved = $model->getConnection()->transaction(function () use ($data, $model, $images) {
                return $this->persist($data, $model, 'create', $images);
            });
            return response()->json($saved, 201);
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    protected function updateResource(array $data, $id)
    {
        try {
            $model = $this->resourceQuery()->whereKey($id)->firstOrFail();
            Gate::authorize('update', $model);
            $images = new ResourceImages();
            $saved = $model->getConnection()->transaction(function () use ($data, $model, $images) {
                return $this->persist($data, $model, 'edit', $images);
            });
            // Keep the existing status code for web and mobile consumers.
            return response()->json($saved, 201);
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function show(Request $request, $id)
    {
        $resource = $this->resourceQuery()->with($this->resourceWith('show'))->whereKey($id)->firstOrFail();
        if (Gate::getPolicyFor($resource)) {
            Gate::authorize('view', $resource);
        }
        return $resource;
    }

    public function destroy($id)
    {
        try {
            $model = new $this->resourceClass();
            $model->getConnection()->transaction(function () use ($id) {
                $resource = $this->resourceQuery()->whereKey($id)->firstOrFail();
                Gate::authorize('delete', $resource);
                if ($this->preDestroy($resource->getKey())) {
                    $resource->delete();
                }
            });
            return response()->json('ok');
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function act(Request $request, $resourceId, $actionName)
    {
        try {
            $data = $this->requestData($request, true);
            $resource = $this->resourceQuery()->whereKey($resourceId)->firstOrFail();
            $result = $resource->getConnection()->transaction(function () use ($resource, $actionName, $data) {
                $result = $this->doAct($resource, $actionName, $data);
                abort_if($result === false || $result === null, 404, 'action_not_found');
                return $result;
            });
            return response()->json($result);
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function doAct($resource, $actionName, $actionData)
    {
        return false;
    }

    public function massive(Request $request)
    {
        $file = $request->file('resources');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return response()->json(['message' => 'A valid resources file is required'], 422);
        }
        try {
            $model = new $this->resourceClass();
            $images = new ResourceImages();
            $saved = $model->getConnection()->transaction(function () use ($file, $images) {
                return (new ResourceCsvImporter())->import($file, (new $this->resourceClass())->resourceImportColumns(), function ($data) use ($images) {
                    $data = Validator::make($data, $this->importRules())->validate();
                    Gate::authorize('create', $this->resourceClass);
                    $this->persist($data, new $this->resourceClass(), 'create', $images);
                });
            });
            return response()->json($saved);
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    protected function resourceQuery(): Builder
    {
        return $this->resourceClass::query()->visibleTo(Auth::user());
    }

    protected function resourceWith(string $context): array
    {
        return $this->with[$context] ?? [];
    }

    protected function newResourcePassword(array $data): string
    {
        $password = is_array($data['password'] ?? null) ? ($data['password']['password'] ?? null) : ($data['password'] ?? null);
        $password ??= $data['user']['password'] ?? Str::random(16);
        if (!is_string($password) || strlen($password) < 8 || strlen($password) > 255) {
            throw ValidationException::withMessages(['password' => ['The password must contain between 8 and 255 characters.']]);
        }
        return $password;
    }

    private function requestData(Request $request, bool $allowEmpty = false): array
    {
        if ($allowEmpty && trim($request->getContent()) === '') {
            return [];
        }
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !str_starts_with(ltrim($request->getContent()), '{')) {
            throw ValidationException::withMessages(['resource' => ['A JSON object is required.']]);
        }
        return $data;
    }

    private function persist(array $data, Model $model, string $context, ResourceImages $images): mixed
    {
        $creating = $context === 'create';
        $data = $creating ? $this->preStore($data, $model) : $this->preUpdate($data, $model);
        $imageFields = [];
        foreach ($this->images ?? [] as $field => $options) {
            $imageFields[] = is_int($field) ? $options : $field;
        }
        $saved = (new ResourceWriter($images))->store($data, $model, $imageFields);
        return $creating ? $this->postStore($data, $saved) : $this->postUpdate($data, $saved);
    }

    protected function importRules(): array
    {
        throw ValidationException::withMessages(['resources' => ['CSV validation is not configured for this resource.']]);
    }

    private function errorResponse(Throwable $exception): JsonResponse
    {
        if ($exception instanceof ValidationException) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => $exception->errors()], 422);
        }
        if ($exception instanceof ModelNotFoundException) {
            return response()->json('resource_not_found', 404);
        }
        if ($exception instanceof AuthorizationException || $exception instanceof HttpExceptionInterface) {
            throw $exception;
        }
        report($exception);
        return response()->json(['message' => 'Server Error'], 500);
    }
    protected function applyOrdering(Builder $query, array $clauses): void
    {
        foreach ($clauses as $clause) {
            [$field, $direction] = $clause;
            $direction = strtolower($direction);
            if (!in_array($direction, ['asc', 'desc'], true)) {
                throw new InvalidArgumentException('Invalid resource sort direction.');
            }
            if (str_contains($field, '.')) {
                $query->orderBy($this->relationOrder($query, $field), $direction);
                continue;
            }
            if (str_ends_with($field, '_count') && !$this->hasCount($query, $field)) {
                $query->withCount(substr($field, 0, -6) . ' as ' . $field);
            }
            $query->orderBy($field, $direction);
        }
    }

    private function relationOrder(Builder $query, string $path): QueryBuilder
    {
        $parts = explode('.', $path);
        $column = array_pop($parts);
        $model = $query->getModel();
        $relations = [];
        foreach ($parts as $field) {
            $relation = $model->{$field}();
            if (!$relation instanceof BelongsTo) {
                throw new InvalidArgumentException('Resource sorting supports belongs-to paths only.');
            }
            $relations[] = $relation;
            $model = $relation->getRelated();
        }

        $relations = array_reverse($relations);
        $order = $model->getConnection()->query()->from($model->getTable(), 'sort_0')->select('sort_0.' . $column);
        foreach ($relations as $index => $relation) {
            if ($index > 0) {
                $previous = $relations[$index - 1];
                $order->join(
                    $relation->getRelated()->getTable() . ' as sort_' . $index,
                    'sort_' . $index . '.' . $previous->getForeignKeyName(),
                    '=',
                    'sort_' . ($index - 1) . '.' . $previous->getOwnerKeyName()
                );
            }
        }
        $last = $relations[count($relations) - 1];
        return $order->whereColumn(
            'sort_' . (count($relations) - 1) . '.' . $last->getOwnerKeyName(),
            $query->getModel()->qualifyColumn($last->getForeignKeyName())
        );
    }

    private function hasCount(Builder $query, string $field): bool
    {
        $grammar = $query->getQuery()->getGrammar();
        $suffix = ' as ' . $grammar->wrap($field);
        foreach ($query->getQuery()->columns ?? [] as $column) {
            $sql = is_string($column) ? $column : $column->getValue($grammar);
            if (str_ends_with($sql, $suffix)) {
                return true;
            }
        }
        return false;
    }
}
