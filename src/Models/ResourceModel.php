<?php

namespace Aptic\Concorde\Models;

use Aptic\Concorde\Models\Concerns\HasResourceRelations;
use Illuminate\Database\Eloquent\Model;

abstract class ResourceModel extends Model
{
    use HasResourceRelations;

    protected array $resourceRelations = [];
}
