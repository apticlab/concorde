<?php

namespace Aptic\Concorde\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Aptic\Concorde\helpers;
use BadMethodCallException;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use SplFileObject;

function getFileDelimiter($file, $checkLines = 2){
    $file = new SplFileObject($file);
    $delimiters = [
        ",",
        "\t",
        ";",
        "|",
        ":"
    ];

    $results = array();
    $i = 0;

    while ($file->valid() && $i <= $checkLines) {
        $line = $file->fgets();

        foreach ($delimiters as $delimiter){
            $regExp = '/['.$delimiter.']/';
            $fields = preg_split($regExp, $line);

            if (count($fields) > 1) {
                if (!empty($results[$delimiter])) {
                    $results[$delimiter]++;
                } else {
                    $results[$delimiter] = 1;
                }
            }
        }

        $i++;
    }
    if (empty($results)) {
        return ',';
    }

    $results = array_keys($results, max($results));

    return $results[0];
}

class ResourceBaseController extends Controller
{
    private static $columnsByTable = [];
    public $resourceClass = null;
    public $relatedResources = [];
    public $massiveHeaders = [];
    public $validators = [
        "create" => null,
        "edit" => null,
    ];
    public $orderBy = [];

    public function index(Request $req) {
        if (!$this->resourceClass) {
            return null;
        }

        $requestParams = $req->input();

        $query = $this->resourceQuery();

        // Index filter
        if (method_exists($this, "indexFilter")) {
            $query = $this->indexFilter($query, $requestParams);
        }


        $indexWith = $this->resourceWith('index');
        if (!empty($indexWith)) {
            $query->with($indexWith);
        }

        if (isset($this->withCount) && isset($this->withCount['index'])) {
            $query->withCount($this->withCount['index']);
        }

        if (isset($this->orderBy) && count($this->orderBy) > 0) {
            foreach ($this->orderBy as $orderByClause) {
                if (strpos($orderByClause[0], ".") != false) {
                    $orderByDirection = $orderByClause[1];
                    $orderByFunction = $orderByDirection == 'asc' ? "orderBy" : "orderByDesc";

                    $resourceClasses = $orderByClause[2];
                    $tokens = explode(".", $orderByClause[0]);
                    $fieldName = array_pop($tokens);

                    $tokens = array_reverse($tokens);

                    $tables = [];

                    foreach ($tokens as $tableName) {
                        $tables[] = [
                            "name" => app($resourceClasses[$tableName])->getTable(),
                            "field" => $tableName,
                        ];
                    }

                    $orderQuery = DB::query();

                    foreach ($tables as $index => $table) {
                        if ($index == 0) {
                            $orderQuery
                                ->from($table['name'], "table" . $index)
                                ->select($fieldName);
                        } else {
                            $tableAlias = "table" . $index;
                            $prevTableAlias = "table" . ($index - 1);
                            $prevTableName = $tables[$index - 1];

                            $orderQuery->join(
                                $table['name'] . " as $tableAlias",
                                $tableAlias . "." . $prevTableName['field'] . "_id",
                                $prevTableAlias . ".id"
                            );
                        }
                    }

                    $lastIndex = count($tables) - 1;
                    $lastTable = $tables[$lastIndex];

                    $orderQuery
                        ->whereColumn(
                            "table" . $lastIndex . ".id",
                            app($this->resourceClass)->getTable() . "." . $lastTable['field'] . "_id"
                        );

                    $query->{$orderByFunction}($orderQuery);
                    continue;
                }

                if (strpos($orderByClause[0], "_count")) {
                    $countField = explode("_", $orderByClause[0])[0];
                    $query
                        ->withCount([$countField . " as " . $orderByClause[0]])
                        ->orderBy($orderByClause[0], $orderByClause[1]);
                    continue;
                }

                $query->orderBy($orderByClause[0], $orderByClause[1]);
            }
        }

        if (config('app.debug')) {
            Log::debug('Resource index query', [
                'resource' => $this->resourceClass,
                'sql' => $query->toSql(),
                'bindings' => $query->getBindings(),
            ]);
        }

        $paginateFromController = $this->paginate ?? false;

        $noPaginate = filter_var($requestParams['no_paginate'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($paginateFromController && !$noPaginate) {
            $paginationRows = $this->paginationRows ?? 10;
            return $query->paginate($paginationRows);
        }

        return $query->get();
    }

    public function roleBaseFilter($query, $user) {
        return $query;
    }

    public function indexFilter($query, $params) {
        return $query;
    }

    public function preStore($resourceData, $resourceModel) {
        return $resourceData;
    }

    public function postStore($resourceData, $resourceModel) {
        return $resourceData;
    }

    public function preDestroy($resourceId) {
        return true;
    }

    public function store(Request $request) {
        try {
            $resourceData = json_decode($request->getContent(), true);
            if (!is_array($resourceData)) {
                return response()->json(['message' => 'The given data was invalid'], 422);
            }
            $resourceModel = new $this->resourceClass();
            if (isset($this->validators['create'])) {
                $validator = Validator::make($resourceData, $this->validators['create']);
                if ($validator->fails()) {
                    return response()->json([
                        "message" => "The given data was invalid",
                        "errors" => $validator->errors(),
                    ], 422);
                }
            }

            $savedModel = DB::transaction(function () use ($resourceData, $resourceModel) {
                $newIDLookup = [];
                $resourceData = $this->preStore($resourceData, $resourceModel);
                $savedModel = $this->resourceStore($resourceData, $resourceModel, $newIDLookup);

                return $this->postStore($resourceData, $savedModel);
            });

            return response()->json($savedModel, 201);
        } catch (\Throwable $e) {
            return $this->serverError($e);
        }
    }

    public function preUpdate($resourceData, $resourceModel) {
        return $resourceData;
    }

    public function update(Request $request, $id) {
        try {
            $resourceData = json_decode($request->getContent(), true);
            if (!is_array($resourceData)) {
                return response()->json(['message' => 'The given data was invalid'], 422);
            }
            $resourceModel = $this->resourceQuery()->whereKey($id)->firstOrFail();

            if (isset($this->validators['edit'])) {
                $validator = Validator::make($resourceData, $this->validators['edit']);

                if ($validator->fails()) {
                    return response()->json([
                        "message" => "The given data was invalid",
                        "errors" => $validator->errors(),
                    ], 422);
                }
            }

            $savedModel = DB::transaction(function () use ($resourceData, $resourceModel) {
                $newIDLookup = [];
                $resourceData = $this->preUpdate($resourceData, $resourceModel);
                $savedModel = $this->resourceStore($resourceData, $resourceModel, $newIDLookup);

                return $this->postUpdate($resourceData, $savedModel);
            });

            return response()->json($savedModel, 201);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json('resource_not_found', 404);
        } catch (\Throwable $e) {
            return $this->serverError($e);
        }
    }

    public function postUpdate($resourceData, $resourceModel) {
        return $resourceData;
    }

    public function show(Request $request, $id) {
        $query = $this->resourceQuery();

        $showWith = $this->resourceWith('show');
        if (!empty($showWith)) {
            $query->with($showWith);
        }

        return $query->whereKey($id)->firstOrFail();
    }

    public function destroy($id) {
        try {
            DB::transaction(function () use ($id) {
                $resource = $this->resourceQuery()->whereKey($id)->firstOrFail();
                if ($this->preDestroy($resource->id)) {
                    $resource->delete();
                }
            });

            return response()->json("ok", 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json('resource_not_found', 404);
        } catch (\Throwable $e) {
            return $this->serverError($e);
        }
    }

    public function act(Request $req, $resourceId, $actionName) {
        if (method_exists($this, "doAct")) {
            $actionData = json_decode($req->getContent(), true);

            $query = $this->resourceQuery();

            $resource = $query->where("id", $resourceId)->first();

            if (!$resource) {
                return response()->json("resource_not_found", 404);
            }

            try {
                $result = DB::transaction(function () use ($resource, $actionName, $actionData) {
                    $result = $this->doAct($resource, $actionName, $actionData);
                    if (!$result) {
                        throw new Exception("action_not_found");
                    }

                    return $result;
                });

                return response()->json($result, 200);
            } catch (\Throwable $e) {
                return $this->serverError($e);
            }
        }
    }

    public function massive(Request $req) {
        $resourcesFile = $req->file("resources");

        if (!$resourcesFile instanceof UploadedFile || !$resourcesFile->isValid()) {
            return response()->json(['message' => 'A valid resources file is required'], 422);
        }

        try {
            $savedResources = DB::transaction(function () use ($resourcesFile) {
                return $this->doMassiveStore($resourcesFile);
            });

            return response()->json($savedResources, 200);
        } catch (ValidationException $e) {
            return response()->json($e->validator, 422);
        } catch (\Throwable $e) {
            return $this->serverError($e);
        }
    }

    private function doMassiveStore(UploadedFile $resourcesFile) {
        $filePath = $resourcesFile->getRealPath();
        $fileHandle = fopen($filePath, "r");

        $csvDelimiter = getFileDelimiter($filePath);

        Log::info("Csv delimiter: $csvDelimiter");

        // Get the first row as headers
        $headers = fgetcsv($fileHandle, 0, $csvDelimiter);
        if (!$headers) {
            throw ValidationException::withMessages(['resources' => ['The CSV file has no header row.']]);
        }

        foreach ($this->massiveHeaders as $massiveHeader) {
            if (!in_array($massiveHeader['columnName'], $headers, true)) {
                throw ValidationException::withMessages([
                    'resources' => ["Missing required CSV column: {$massiveHeader['columnName']}"],
                ]);
            }
        }

        $errors = [];
        $savedResource = 0;
        while (($csvRow = fgetcsv($fileHandle, 0, $csvDelimiter)) !== false) {
            if (count($csvRow) !== count($headers)) {
                $errors[] = ['resources' => ['A CSV row does not match the header column count.']];
                continue;
            }

            $row = array_combine($headers, $csvRow);
            $resourceRow = [];
            foreach ($this->massiveHeaders as $index => $massiveHeader) {
                switch ($massiveHeader['type']) {
                case 'resource':
                    $whereOperator = $massiveHeader['operator'] ?? '=';

                    $relatedResource = $massiveHeader['resourceClass']
                        ::where($massiveHeader['foreignField'], $whereOperator, $row[$massiveHeader['columnName']])
                            ->first();

                    if ($relatedResource) {
                        $resourceRow[$massiveHeader['field']] = $relatedResource->id;
                    }
                    break;
                case 'date':
                    if (!$row[$massiveHeader['columnName']]) {
                        continue 2;
                    }

                    $dateObject = Carbon::createFromFormat($massiveHeader['inFormat'], $row[$massiveHeader['columnName']]);
                    $resourceRow[$massiveHeader['field']] = $dateObject->format($massiveHeader['outFormat']);

                    break;
                default:
                    $resourceRow[$massiveHeader['field']] = $row[$massiveHeader['columnName']];
                    break;
                }
            }

            try {
                $model = new $this->resourceClass();
                $newIDLookup = [];
                $this->resourceStore($resourceRow, $model, $newIDLookup);
                $savedResource += 1;
            } catch (ValidationException $e) {
                Log::info($e->validator);
                $errors[] = $e->validator;
            }
        }

        if (count($errors) > 0) {
            throw new ValidationException($errors);
        }

        return $savedResource;
    }

    public function doAct($resource, $actionName, $actionData) {
        return false;
    }

    private function resourceStore($resource, $model, array &$newIDLookup = []) {
        $resourceData = [];
        foreach ($this->columnsFor($model) as $columnName) {
            if (isset($this->images) && array_key_exists($columnName, $this->images)) {
                continue;
            }

            // isset return false even if the key is present but it's null
            // We need to ensure that if the key is present the value gets updated, whichever the value
            if (array_key_exists($columnName, $resource)) {
                $resourceData[$columnName] = $resource[$columnName];
            }
        }

        $model->fill($resourceData);
        $model->save();

        if ($resource != null) {
                foreach ($resource as $field => $value) {
                    // Check if we need to upload an image
                    if(isset($this->images)) {
                        if (array_key_exists($field, $this->images) && !!$value && str_starts_with($value, "data")) {
                            $this->saveImage($model, $value, $field);
                            continue;
                        }
                    }

                    // Check which type of relationship we have
                    try {
                        if (!$model->isRelation($field)) {
                            continue;
                        }

                        $relation = $model->{$field}();
                        if (!$relation instanceof Relation) {
                            continue;
                        }

                        $relationType = class_basename($relation);
                        $relatedResourceModelClass = get_class($relation->getRelated());
                    } catch (\Throwable $e) {
                        continue;
                    }

                    Log::info("Dealing with relation: " . $relatedResourceModelClass . " of type: " . $relationType . " from field: " . $field);

                    switch ($relationType) {
                    case 'BelongsTo':
                        $relatedResource = $resource[$field];

                        // Update just one related resource
                        if (
                            !isset($relatedResource['id']) or
                            (str_starts_with($relatedResource['id'], "NEW_") and !isset($newIDLookup[$relatedResource['id']]['id']))
                        ) {
                            // Create new related resource
                            $relatedResourceModel = new $relatedResourceModelClass();

                            if (isset($relatedResource['id'])) {
                                //Save to get the ID
                                $relatedResourceModel->save();
                                $newIDLookup[$relatedResource['id']] = [
                                    "id" => $relatedResourceModel->id,
                                    "model" => $relatedResourceModel
                                ];
                            }

                            // Remove id from resource, since it can be in the form NEW_###
                            unset($relatedResource['id']);
                        } else {
                            if (isset($newIDLookup[$relatedResource['id']])) {
                                Log::info("Old NEW_ resource ID: " . $relatedResource['id'] . " actual one: " . $newIDLookup[$relatedResource['id']]['id']);
                                $relatedResourceModel = $newIDLookup[$relatedResource['id']]['model'];
                                $relatedResource['id'] = $newIDLookup[$relatedResource['id']]['id'];
                            } else {
                                // Update new related resource
                                $relatedResourceModel = $relatedResourceModelClass::where("id", $relatedResource['id'])->first();
                            }

                        }
                        if (!(isset($model->readonly) && in_array($field, $model->readonly)) && !!$relatedResource) {
                            // Store related resource with this function
                            Log::info("Not updating model " . $field . " on resource " . $model->id . " but only the relation");
                            $relatedResourceModel = $this->resourceStore($relatedResource, $relatedResourceModel, $newIDLookup);
                        }

                        // BelongsTo the foreign key is on the "parent" model
                        $fkName = $model->{$field}()->getForeignKeyName();
                        $model->{$fkName} = $relatedResourceModel->id;
                        $model->save();
                        break;

                    case 'HasMany':
                    case 'HasManyThrough':
                        $relatedResources = $resource[$field];
                        $oldRelatedResourceIds = $model->{$field}->pluck("id")->toArray();
                        $currentRelatedResourcesIds = [];

                        Log::info("Old related res ids: " . implode(", ", $oldRelatedResourceIds));

                        foreach ($relatedResources as $relatedResource) {
                            // Update just one related resource
                            if (
                                !isset($relatedResource['id']) or
                                (str_starts_with($relatedResource['id'], "NEW_") and !isset($newIDLookup[$relatedResource['id']]['id']))
                            ) {
                                Log::info("Creating a new related resource!");
                                // Create new related resource
                                $relatedResourceModel = new $relatedResourceModelClass();

                                if (isset($relatedResource['id'])) {
                                    //Save to get the ID
                                    $relatedResourceModel->save();
                                    $newIDLookup[$relatedResource['id']] = [
                                        "id" => $relatedResourceModel->id,
                                        "model" => $relatedResourceModel,
                                    ];
                                }

                                // Remove id from resource, since it can be in the form NEW_###
                                unset($relatedResource['id']);
                            } else {
                                Log::info("Related resource altready exists");

                                if (isset($newIDLookup[$relatedResource['id']]['id'])) {
                                    $relatedResourceModel = $newIDLookup[$relatedResource['id']]['model'];
                                    $relatedResource['id'] = $newIDLookup[$relatedResource['id']]['id'];
                                } else {
                                    // Update new related resource
                                    Log::info("Update related resource: " . $relatedResource['id']);
                                    $relatedResourceModel = $relatedResourceModelClass::where("id", $relatedResource['id'])->first();
                                }
                            }

                            if ($relationType == "HasMany") {
                                // Get the foreign key name of the related model in its own table
                                // es. Card->hasMany(CardExercise) => getForeignKeyName = "card_id"
                                $relatedResource[$model->{$field}()->getForeignKeyName()] = $model->id;

                                $relatedResourceModel->{$model->{$field}()->getForeignKeyName()} = $model->id;
                                $relatedResourceModel->save();

                                $currentRelatedResourcesIds[] = $relatedResourceModel->id;
                            } else {
                                $fkName = $model->{$field}()->getForeignKeyName();

                                if (isset($relatedResourceModel)) {
                                    $relatedResourceModel->{$fkName} = $relatedResource[$fkName];
                                    $relatedResourceModel->save();

                                    $currentRelatedResourcesIds[] = $relatedResourceModel->id;
                                }

                            }

                            // Store related resource with this function
                            if (!(isset($model->readonly) && in_array($field, $model->readonly)) && !!$relatedResource) {
                                // Store related resource with this function
                                $relatedResourceModel = $this->resourceStore($relatedResource, $relatedResourceModel, $newIDLookup);
                            }
                        }

                        // Delete owned no more used related resource
                        $resourcesToDeleteIds = array_diff($oldRelatedResourceIds, $currentRelatedResourcesIds);

                        foreach ($resourcesToDeleteIds as $resourceId) {
                            Log::info("Deleting $resourceId");
                            $relatedResourceModelClass::destroy($resourceId);
                        }

                        break;

                    case 'HasOne':
                        $relatedResource = $resource[$field];
                        // Update just one related resource
                        if (!isset($relatedResource['id'])) {
                            // Create new related resource
                            $relatedResourceModel = new $relatedResourceModelClass();
                        } else {
                            // Update new related resource
                            $relatedResourceModel = $relatedResourceModelClass::where("id", $relatedResource['id'])->first();
                        }

                        // Get the foreign key name of the related model
                        $relatedResource[$model->{$field}()->getForeignKeyName()] = $model->id;

                        // Store related resource with this function
                        if (!(isset($model->readonly) && in_array($field, $model->readonly)) && !!$relatedResource) {
                            // Store related resource with this function
                            $relatedResourceModel = $this->resourceStore($relatedResource, $relatedResourceModel, $newIDLookup);
                            $currentRelatedResourcesIds[] = $relatedResourceModel->id;
                        }
                        break;

                    case 'BelongsToMany':
                        $relatedResources = $resource[$field];

                        $relatedResourcesIds = array_column($relatedResources, "id");

                        $model->{$field}()->sync($relatedResourcesIds);
                        break;
                    }
                }
        }

        return $model;
    }

    private function resourceQuery()
    {
        $query = $this->resourceClass::query();
        return $this->roleBaseFilter($query, Auth::user()) ?: $query->whereRaw('1 = 0');
    }

    protected function resourceWith(string $context): array
    {
        return $this->with[$context] ?? [];
    }

    private function columnsFor(Model $model)
    {
        $key = $model->getConnectionName() . ':' . $model->getTable();
        if (!array_key_exists($key, self::$columnsByTable)) {
            self::$columnsByTable[$key] = Schema::getColumnListing($model->getTable());
        }

        return self::$columnsByTable[$key];
    }

    private function serverError(\Throwable $e)
    {
        report($e);
        return response()->json(['message' => 'Server Error'], 500);
    }

    private function saveImage($model, $value, $field) {
        $imageTokens = explode(",", $value, 2);
        if (count($imageTokens) !== 2) {
            throw ValidationException::withMessages([$field => ['The image payload is invalid.']]);
        }
        $imageInfo = $imageTokens[0];
        $imageContent = $imageTokens[1];
        $imageExtension = "";

        $matches = [];

        // Try to find the extension from image info
        preg_match('/^data:image\/(\w+);base64/', $imageInfo, $matches);

        if (count($matches) <= 1) {
            throw ValidationException::withMessages([$field => ['The image payload is invalid.']]);
        }
        $imageExtension = $matches[1];

        $decodedImage = base64_decode($imageContent, true);
        if ($decodedImage === false) {
            throw ValidationException::withMessages([$field => ['The image payload is invalid.']]);
        }

        $imageName = $field . "_" . $model->id . "_" . Carbon::now()->timestamp . "." . $imageExtension;

        $storageDisk = config("concorde.storageDisk", "local");

        $imageSaved = Storage::disk($storageDisk)->put($imageName, $decodedImage, "public");

        if ($imageSaved) {
            // Delete old image to reduce space usage
            $oldImageFileTokens = explode("/", $model->{$field});
            $oldImageFileName = end($oldImageFileTokens);
            $oldImageDeleted = Storage::disk($storageDisk)->delete($oldImageFileName);

            $imageUrl = Storage::disk($storageDisk)->url($imageName);

            $model->{$field} = $imageUrl;
            $model->save();
        }
    }
}
