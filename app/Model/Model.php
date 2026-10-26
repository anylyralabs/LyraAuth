<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model as BaseModel;
use Hyperf\Database\Model\SoftDeletes;

abstract class Model extends BaseModel
{
    public bool $timestamps = true;

    protected array $guarded = ['id'];

    protected ?string $dateFormat = 'Y-m-d H:i:s';
}
