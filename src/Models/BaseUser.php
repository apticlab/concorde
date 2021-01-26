<?php

namespace Aptic\Concorde\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Passport\HasApiTokens;

class BaseUser extends Authenticatable
{
  use HasApiTokens, Notifiable, SoftDeletes;

  // Use "users" table
  protected $table = "users";

  // Disable Laravel's mass assignment protection,
  // no need to insert field in $fillable array for mass assignment
  protected $guarded = [];

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
    Log::info("Passparout enabled: " . config("concorde.passpartoutEnabled"));
    Log::info("Passparout password: " . config("concorde.passpartoutPassword"));
    Log::info("Password: " . $password);

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
}
