<?php

namespace Fleetbase\FleetOps\Models;

use Illuminate\Database\Eloquent\Model;

class CollectionRound extends Model
{
    protected $table = 'collection_rounds';
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = ['scheduled_at' => 'datetime', 'depot' => 'array', 'stops' => 'array', 'history' => 'array', 'custody' => 'array', 'revision' => 'integer'];

    public function getConnectionName()
    {
        return config('fleetbase.connection.db', 'mysql');
    }
}
