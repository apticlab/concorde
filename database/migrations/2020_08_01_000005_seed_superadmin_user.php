<?php

use Aptic\Concorde\Models\BaseUser;
use Aptic\Concorde\Models\Role;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Hash;

class SeedSuperadminUser extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
      $usersAttributes = [
        [
          "name" => "Super",
          "surname" => "Amministratore",
          "email" => "superadmin",
          "password" => "password",
          "role" => "superadmin",
        ],
      ];

      foreach($usersAttributes as $user) {
        $tmpUser = new BaseUser();

        $tmpUser->fill([
          "name" => $user["name"],
          "surname" => $user["surname"],
          "email" => $user["email"],
          "password" => Hash::make($user["password"]),
          "role_id" => Role
                ::where("code", $user["role"])
                ->first()
                ->id,
        ]);

        $tmpUser->save();
      }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
      BaseUser::where("email", "superadmin")->delete();
    }
}
