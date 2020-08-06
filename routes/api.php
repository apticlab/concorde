<?php

use Aptic\Concorde\Http\Controllers\LoginController;

Route::post("login", [LoginController::class, "login"])->name("login");
Route::post("login/resetpassword", [LoginController::class, "resetPassword"])->name("resetpassword");
