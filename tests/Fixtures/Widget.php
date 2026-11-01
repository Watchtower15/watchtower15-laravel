<?php

declare(strict_types=1);

namespace WatchTower15\Laravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** @property int $amount */
final class Widget extends Model
{
    protected $table = 'widgets';

    protected $guarded = [];

    public $timestamps = false;
}
