<?php

use Aptic\Concorde\Models\Role;
use Aptic\Concorde\Models\BaseUser;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

class SeedRoles extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
      $rolesAttributes = [
        [
          "code" => "superadmin",
          "color" => "#e74c3c",
          "name" => "SuperAmministratore",
        ],
      ];

      foreach($rolesAttributes as $role) {
        $tmpRole = new Role();

        $tmpRole->fill($role);
        $tmpRole->save();
      }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
      Role::get()->delete();
    }
}
