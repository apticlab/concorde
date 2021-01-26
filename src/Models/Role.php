<?php

namespace Aptic\Concorde\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class Role extends Model
{
  public $visible = [
    "id",
    "code",
    "name",
    "color",
  ];

  public $fillable = [
    "code",
    "name",
    "color",
  ];

  protected static function boot() {
    parent::boot();

    static::addGlobalScope('userCanSee', function (Builder $builder) {
      $authUser = Auth::user();

      if ($authUser == null) {
        return;
      }

      switch ($authUser->role && $authUser->role->code) {
        case "superadmin":
          break;

        default:
          $builder->where("code", "!=", "superadmin");
      }
    });
  }
}
