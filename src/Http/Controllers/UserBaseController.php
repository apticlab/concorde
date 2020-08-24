<?php

namespace Aptic\Concorde\Http\Controllers;

use Aptic\Concorde\Models\BaseUser;
use Aptic\Concorde\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class UserBaseController extends ResourceBaseController
{
  public $role = "";
  public $resourceClass = BaseUser::class;

  public $images = [
    'avatar'
  ];

  public function indexFilter($query, $params)
  {
    if ($this->role == "") {
      return $query;
    }

    return $query->ofType($this->role);
  }

  public function preStore($userData, $userModel)
  {
    // Add custom fields
    if ($this->role != "") {
      $userData['role_id'] = Role::where("code", $this->role)->first()->id;
    }

    $userPassword = isset($userData['password']) ? $userData['password'] : "password";
    $userData['password'] = Hash::make($userPassword);
    $userData['full_name'] = ucfirst($userData['surname']) . " " . ucfirst($userData['name']);

    return $userData;
  }

  public function preUpdate($userData, $userModel)
  {
    $userData['full_name'] = ucfirst($userData['surname']) . " " . ucfirst($userData['name']);

    return $userData;
  }
}
