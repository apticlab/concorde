<?php

namespace Aptic\Concorde\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Aptic\Concorde\helpers;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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

    $requestParams = Input::all();

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

    Log::info("Query: " . $this->getSqlQueryWithBindings($query));

    if (isset($this->orderBy) && count($this->orderBy) > 1) {
      $query->orderBy(...$this->orderBy);
    }

    if (isset($this->paginate)) {
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

  public function store(Request $request) {
    try {
      $resourceData = json_decode($request->getContent(), true);
      $resourceModel = new $this->resourceClass();

      if (isset($this->validators['create'])) {
        $validator = Validator::make($resourceData, $this->validators['create']);

        if ($validator->fails()) {
          return response()->json($validator->errors(), 422);
        }
      }

      if (method_exists($this, "preStore")) {
        $resourceData = $this->preStore($resourceData, $resourceModel);
      }

      $savedModel = $this->resourceStore($resourceData, $resourceModel);

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
          return response()->json($validator->errors(), 422);
        }
      }

      if (method_exists($this, "preUpdate")) {
        $resourceData = $this->preUpdate($resourceData, $resourceModel);
      }

      $savedModel = $this->resourceStore($resourceData, $resourceModel);

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

  public function show($id) {
    return $this->resourceClass::where("id", "=", $id)->first();
  }

  public function destroy($id) {
    try {
      $this->resourceClass::destroy($id);

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
      $resource = $this->resourceClass::where("id", $resourceId)->first();

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

  private function resourceStore($resource, $model) {
    DB::beginTransaction();

    $resourceData = [];

    foreach ($resource as $field => $value) {
      $fieldName = "";
      $fieldValue = null;

      switch (gettype($value)) {
        case 'array':
          if (isset($value['id'])) {
            $fieldName = $field . "_id";
            $fieldValue = $value['id'];
          }
          break;

        default:
          $fieldName = $field;
          $fieldValue = $value;
      }

      if (Schema::hasColumn($model->getTable(), $fieldName)) {
        // Check if we are adding an attribute or a column
        $resourceData[$fieldName] = $fieldValue;
      }
    }

    try {
      $model->fill($resourceData);
      $model->save();

      // Save related resources
      foreach ($resource as $field => $value) {
        switch (gettype($value)) {
          case 'array':
            // Multi sync relationship
            if ($this != null && !isset($this->relatedResources) || $this->relatedResources == []) {
              break;
            }

            $relatedResourceData = [];

            foreach ($this->relatedResources as $name => $data) {
              if ($name == $field) {
                $relatedResourceData = $data;
              }
            }

            if ($relatedResourceData == []) {
              break;
            }

            $resourceIsOwned = false;

            if (isset($relatedResourceData['owned'])) {
              // When resource is owned, it means the on the child resource
              // we have the id of the parent resource.
              // Otherwise, we need to have a normal Many-To-Many relationship
              $resourceIsOwned = $relatedResourceData['owned'];
            }

            Log::info("Saving current related resources ids");

            $relatedResourceType = $relatedResourceData['type'] ?? 'many-to-many';

            if ($relatedResourceType == 'many-to-many') {
              // Keep a copy of old related resources id to delete the no more used ones
              $oldRelatedResourceIds = $model->{$field}->pluck("id")->toArray();
              $currentRelatedResourcesIds = [];

              foreach ($value as $index => $relatedResource) {
                if (!isset($relatedResource['id'])) {
                  // Create new related resource
                  Log::info("Creating new related resource");
                  $relatedResourceModel = new $relatedResourceData['class']();
                } else {
                  // Update new related resource
                  Log::info("Updating new related resource");
                  $relatedResourceModel = $relatedResourceData['class']::where("id", $relatedResource['id'])->first();
                }

                if ($resourceIsOwned) {
                  $relatedResource[$this->singular . "_id"] = $model->id;
                }

                // Store related resource with this function
                $relatedResourceModel = $this->resourceStore($relatedResource, $relatedResourceModel);

                $currentRelatedResourcesIds[] = $relatedResourceModel->id;
              }

              if (!$resourceIsOwned) {
                Log::info("Syncing related resource");
                $model->{$field}->sync($currentRelatedResourcesIds);
                break;
              }

              // Delete owned no more used related resource
              Log::Info("Deleting no more related resources");
              $resourcesToDeleteIds = array_diff($oldRelatedResourceIds, $currentRelatedResourcesIds);

              foreach ($resourcesToDeleteIds as $resourceId) {
                Log::info("Deleting $resourceId");
                $relatedResourceData['class']::destroy($resourceId);
              }
            }

            if ($relatedResourceType == 'one-to-one') {
              $relatedResource = $value;

              // Update just one related resource
              if (!isset($relatedResource['id'])) {
                // Create new related resource
                Log::info("Creating new related resource");
                $relatedResourceModel = new $relatedResourceData['class']();
              } else {
                // Update new related resource
                Log::info("Updating new related resource");
                $relatedResourceModel = $relatedResourceData['class']::where("id", $relatedResource['id'])->first();
              }

              if ($resourceIsOwned) {
                $relatedResource[$this->singular . "_id"] = $model->id;
              } else {
                $model->{$field . "_id"} = $relatedResourceModel->id;
                $model->save();
              }

              // Store related resource with this function
              $relatedResourceModel = $this->resourceStore($relatedResource, $relatedResourceModel);

            }
            break;

          default:
            break;
        }
      }

      DB::commit();

      return $model;
    } catch (\Exception $e) {
      DB::rollBack();

      throw $e;
    }
  }

  private function getSqlQueryWithBindings($query) {
    return Str::replaceArray("?", $query->getBindings(), $query->toSql());
  }
}
