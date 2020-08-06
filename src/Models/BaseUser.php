<?php

namespace Aptic\Concorde\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Passport\HasApiTokens;

class BaseUser extends Authenticatable
{
  use HasApiTokens, Notifiable, SoftDeletes;

  // Disable Laravel's mass assignment protection,
  // no need to insert field in $fillable array for mass assignment
  protected $guarded = [];

  // TODO
  // Automatically remove password without putting it in hidden
  // in order not to delete this when overriding the $hidden property
  private function userByRole($query, $roleCode) {
    return $query
      ->join("roles", "roles.id", "users.role_id")
      ->select("users.*", "roles.code")
      ->distinct("users.id")
      ->where("roles.code", $roleCode);
  }

  public function scopeOfType($query, $type) {
    return $query->where("role_id", Role::where("code", $type)->first()->id);
  }

  public function role() {
    return $this->belongsTo(Role::class, "role_id");
  }

  public function getRole() {
    return $this->role()->first()->code;
  }
}
