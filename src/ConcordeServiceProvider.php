<?php

namespace Aptic\Concorde;

use Aptic\Concorde\Console\AddResource;
use Aptic\Concorde\Console\InstallConcorde;
use Aptic\Concorde\Console\RegenerateResource;
use Aptic\Concorde\Http\ResourceRegistrar;
use Illuminate\Routing\Router;
use Illuminate\Routing\ResourceRegistrar as BaseResourceRegistrar;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ConcordeServiceProvider extends ServiceProvider {
  public function register() {
    $this->mergeConfigFrom(__DIR__ . '/../config/config.php', 'concorde');
  }

  public function boot() {
    if ($this->app->runningInConsole()) {
      if (config("concorde.bootstrap")) {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
      }

      $this->publishes([
        __DIR__.'/../config/config.php' => config_path('concorde.php'),
      ], 'config');

      $this->commands([
        InstallConcorde::class,
        AddResource::class,
        RegenerateResource::class,
      ]);
    }

    app()->bind(BaseResourceRegistrar::class, function () {
      return new ResourceRegistrar(app()->make(Router::class));
    });


    Route::group(["prefix" => "api"], function () {
      $this->loadRoutesFrom(__DIR__.'../../routes/api.php');
    });
  }
}
