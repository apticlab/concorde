<?php

namespace Aptic\Concorde\Console;

use Illuminate\Console\Command;

class RegenerateResource extends Command {
  protected $signature = "resource:regenerate {name}";
  protected $description = "Regenerate a resource";

  public function handle() {
    $this->info("Regenerating resource " . $this->argument("name"));
  }
}
