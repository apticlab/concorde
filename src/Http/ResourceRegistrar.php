<?php

namespace Aptic\Concorde\Http;

use Illuminate\Routing\ResourceRegistrar as BaseResourceRegistrar;

class ResourceRegistrar extends BaseResourceRegistrar {
  protected $resourceDefaults = [
    'index', 'create', 'store', 'show', 'edit', 'update', 'destroy',
    'act', // add act name
    'massive', // add massive endpoint
  ];

  public function addResourceAct($name, $base, $controller, $options) {
    $uri = $this->getResourceUri($name).'/{' . $base . '}/act/{action_name}';

    $action = $this->getResourceAction($name, $controller, 'act', $options);

    return $this->router->post($uri, $action);
  }

  public function addResourceMassive($name, $base, $controller, $options) {
    $uri = $this->getResourceUri($name).'/massive';

    $action = $this->getResourceAction($name, $controller, 'massive', $options);

    return $this->router->post($uri, $action);
  }
}
