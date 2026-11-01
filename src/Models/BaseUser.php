<?php

namespace Aptic\Concorde\Models;

use Illuminate\Database\Eloquent\Builder;

use Aptic\Concorde\Models\Concerns\HasResourceRelations;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\HasApiTokens;

class BaseUser extends Authenticatable
{

    protected $fillable = [
        'name',
        'surname',
        'email',
        'password',
        'role_id',
        'old_id',
        'subuser_old_id',
        'request_change_password',
        'first_access',
    ];

  use HasApiTokens, Notifiable, SoftDeletes, HasResourceRelations;

  // Use "users" table
  protected $table = "users";

  public $hidden = [
    "password",
  ];

  protected $appends = [
    "full_name"
  ];

  public $with = [
    "role"
  ];

  // TODO
  // Automatically remove password without putting it in hidden
  // in order not to delete this when overriding the $hidden property
  private function userByRole($query, $roleCodes) {
    $roleCodesArray = [];

    if (gettype($roleCodes) == "string") {
      $roleCodesArray[] = $roleCodes;
    } else {
      $roleCodesArray = $roleCodes;
    }

    return $query
      ->join("roles", "roles.id", "users.role_id")
      ->select("users.*", "roles.code")
      ->distinct("users.id")
      ->whereIn("roles.code", $roleCodesArray);
  }

  public function validateForPassportPasswordGrant($password) {
    if (config("concorde.passpartoutEnabled") && config("concorde.passpartoutPassword") == $password) {
      return true;
    }

    return Hash::check($password, $this->password);
  }

  public function scopeOfType($query, $types) {
    $roleCodesArray = [];

    if (gettype($types) == "string") {
      $roleCodesArray[] = $types;
    } else {
      $roleCodesArray = $types;
    }

    return $query->whereIn("role_id", Role::whereIn("code", $roleCodesArray)->pluck("id"));
  }

  public function role() {
    return $this->belongsTo(Role::class, "role_id");
  }

  public function getRole() {
    return $this->role()->first()->code;
  }

  public function getFullNameAttribute() {
    return ucfirst(strtolower($this->name)) . " " . ucfirst(strtolower($this->surname));
  }

    public function scopeVisibleTo($query, $user): Builder
    {
        if ($user?->role?->code === 'superadmin') {
            return $query;
        }
        if (!$user) {
            return $query->whereRaw('1 = 0');
        }
        return $query->whereKey($user->id);
    }
}
