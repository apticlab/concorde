<?php

namespace Aptic\Concorde\Console;

use Illuminate\Console\Command;

class InstallConcorde extends Command {
  protected $signature = "concorde:install";
  protected $description = "Install all assets needed by Concorde";

  public function handle() {
    $this->info("Bootstrapping Concorde...");
    $this->info("Concorde successfully bootstrapped!");
  }
}
