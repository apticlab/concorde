<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

class AddRoleToUsers extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
          $table->integer("role_id")->unsigned()->nullable();
          $table->foreign("role_id")->references("id")->on("roles");
          $table->string("name");
          $table->string("surname");
        });

        Artisan::call("db:seed", [
          "--class" => UsersTableSeeder::class,
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
          $table->dropForeign(["role_id"]);
          $table->dropColumn([
            "name",
            "surname",
            "role_id"
          ]);
        });
    }
}
