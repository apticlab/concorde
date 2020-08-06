<?php

namespace Aptic\Concorde\Console;

use Illuminate\Console\Command;

class AddResource extends Command {
  protected $signature = "resource:add {name}";
  protected $description = "Create a resource";

  public function handle() {
    $this->info("Creating resource " . $this->argument("name"));
  }
}
