<?php

namespace Aptic\Concorde\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Aptic\Concorde\helpers;
use BadMethodCallException;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use SplFileObject;
use ReflectionClass;

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
    $results = array_keys($results, max($results));

    return $results[0];
}

class ResourceBaseController extends Controller
{
    public $resourceClass = null;
    public $relatedResources = [];
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

        $query = $this->resourceClass::query();

        // Role base filter
        if (method_exists($this, "roleBaseFilter")) {
            $authUser = Auth::user();

            $query = $this->roleBaseFilter($query, $authUser);
        }

        // Index filter
        if (method_exists($this, "indexFilter")) {
            $query = $this->indexFilter($query, $requestParams);
        }


        if (isset($this->with) && isset($this->with['index'])) {
            $query->with($this->with['index']);
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

        Log::info("Query: " . $this->getSqlQueryWithBindings($query));

        // Disable pagination by setting no_paginate filter in query
        $paginateFromController = $this->paginate ?? false;
        $noPaginateFromQuery = $requestParams['no_paginate'] ?? false;

        if ($paginateFromController && !$noPaginateFromQuery) {
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
        DB::beginTransaction();
        try {
            $resourceData = json_decode($request->getContent(), true);
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

            $newIDLookup = [];

            if (method_exists($this, "preStore")) {
                $resourceData = $this->preStore($resourceData, $resourceModel);
            }

            $savedModel = $this->resourceStore($resourceData, $resourceModel, $newIDLookup);

            if (method_exists($this, "postStore")) {
                $savedModel = $this->postStore($resourceData, $savedModel);
            }

            DB::commit();
            return response()->json($savedModel, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                "message" => $e->getMessage(),
                "file" => $e->getFile(),
                "line" => $e->getLine(),
            ], 500);
        }
    }

    public function preUpdate($resourceData, $resourceModel) {
        return $resourceData;
    }

    public function update(Request $request, $id) {
        try {
            $resourceData = json_decode($request->getContent(), true);
            $resourceModel = $this->resourceClass::find($id);

            if (isset($this->validators['edit'])) {
                $validator = Validator::make($resourceData, $this->validators['edit']);

                if ($validator->fails()) {
                    return response()->json([
                        "message" => "The given data was invalid",
                        "errors" => $validator->errors(),
                    ], 422);
                }
            }

            $newIDLookup = [];

            if (method_exists($this, "preUpdate")) {
                $resourceData = $this->preUpdate($resourceData, $resourceModel);
            }

            $savedModel = $this->resourceStore($resourceData, $resourceModel, $newIDLookup);

            if (method_exists($this, "postUpdate")) {
                $resourceData = $this->postUpdate($resourceData, $resourceModel);
            }

            return response()->json($savedModel, 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                "message" => $e->getMessage(),
                "file" => $e->getFile(),
                "line" => $e->getLine(),
            ], 500);
        }
    }

    public function postUpdate($resourceData, $resourceModel) {
        return $resourceData;
    }

    public function show(Request $request, $id) {
        $query = $this->resourceClass::query();

        if (isset($this->with) && isset($this->with['show'])) {
            $query->with($this->with['show']);
        }

        return $query->where("id", "=", $id)->first();
    }

    public function destroy($id) {
        try {
            $canDestroy = true;

            if (method_exists($this, "preDestroy")) {
                $canDestroy = $this->preDestroy($id);
            }

            if ($canDestroy) {
                $this->resourceClass::destroy($id);
            }

            return response()->json("ok", 200);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                "message" => $e->getMessage(),
                "file" => $e->getFile(),
                "line" => $e->getLine(),
            ], 500);
        }
    }

    public function act(Request $req, $resourceId, $actionName) {
        if (method_exists($this, "doAct")) {
            $actionData = json_decode($req->getContent(), true);

            $query = $this->resourceClass::query();

            // Role base filter
            if (method_exists($this, "roleBaseFilter")) {
                $authUser = Auth::user();

                $query = $this->roleBaseFilter($query, $authUser);
            }

            $resource = $query->where("id", $resourceId)->first();

            if (!$resource) {
                return response()->json("resource_not_found", 404);
            }

            try {
                DB::beginTransaction();
                $result = $this->doAct($resource, $actionName, $actionData);

                if (!$result) {
                    throw new Exception("action_not_found");
                }

                DB::commit();

                return response()->json($result, 200);
            } catch (\Exception $e) {
                DB::rollBack();

                return response()->json([
                    "message" => $e->getMessage(),
                    "file" => $e->getFile(),
                    "line" => $e->getLine(),
                ], 500);
            }
        }
    }

    public function massive(Request $req) {
        $resourcesFile = $req->file("resources");

        try {
            DB::beginTransaction();
            $savedResources = $this->doMassiveStore($resourcesFile);
            DB::commit();

            return response()->json($savedResources, 200);
        } catch (ValidationException $e) {
            return response()->json($e->validator, 422);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                "message" => $e->getMessage(),
                "file" => $e->getFile(),
                "line" => $e->getLine(),
            ], 500);
        }
    }

    private function doMassiveStore(UploadedFile $resourcesFile) {
        $filePath = $resourcesFile->getRealPath();
        $fileHandle = fopen($filePath, "r");

        $csvDelimiter = getFileDelimiter($filePath);

        Log::info("Csv delimiter: $csvDelimiter");

        $rows = [];

        // Get the first row as headers
        $headers = fgetcsv($fileHandle, 0, $csvDelimiter);

        // Put each csv row inside associative array
        while (($csvRow = fgetcsv($fileHandle, 0, $csvDelimiter)) !== FALSE) {
            $row = [];

            foreach ($headers as $index => $header) {
                $row[$header] = $csvRow[$index];
            }

            $rows[] = $row;
        }

        $resourceRows = [];

        foreach ($rows as $row) {
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

            $resourceRows[] = $resourceRow;
        }

        $errors = [];
        $savedResource = 0;

        foreach ($resourceRows as $resourceRow) {
            try {
                $this->doStore($resourceRow);
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

    private function resourceStore($resource, $model, $newIDLookup = []) {
        DB::beginTransaction();

        $resourceData = [];
        foreach (Schema::getColumnListing($model->getTable()) as $columnName) {
            if (isset($this->images) && array_key_exists($columnName, $this->images)) {
                continue;
            }

            // isset return false even if the key is present but it's null
            // We need to ensure that if the key is present the value gets updated, whichever the value
            if (array_key_exists($columnName, $resource)) {
                $resourceData[$columnName] = $resource[$columnName];
            }
        }

        try {
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
                        $relationType = array_reverse(explode("\\", get_class($model->{$field}())))[0];
                        $relatedResourceModelClass = get_class($model->{$field}()->getRelated());
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


            DB::commit();
            return $model;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::info($e);
            throw $e;
        }
    }

    private function getSqlQueryWithBindings($query) {
        return Str::replaceArray("?", $query->getBindings(), $query->toSql());
    }

    private function saveImage($model, $value, $field) {
        $imageTokens = explode(",", $value);
        $imageInfo = $imageTokens[0];
        $imageContent = $imageTokens[1];
        $imageExtension = "";

        $matches = [];

        // Try to find the extension from image info
        preg_match('/^data:image\/(\w+);base64/', $imageInfo, $matches);

        if (count($matches) > 1) {
            $imageExtension = $matches[1];
        }

        $imageName = $field . "_" . $model->id . "_" . Carbon::now()->timestamp . "." . $imageExtension;

        $storageDisk = config("concorde.storageDisk", "local");

        $imageSaved = Storage::disk($storageDisk)->put($imageName, base64_decode($imageContent), "public");

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
