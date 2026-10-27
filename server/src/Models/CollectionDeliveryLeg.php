<?php

namespace Fleetbase\FleetOps\Models;

use Illuminate\Database\Eloquent\Model;

class CollectionDeliveryLeg extends Model
{
    protected $table = 'collection_delivery_legs';
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = ['manifest' => 'array', 'history' => 'array', 'revision' => 'integer'];

    public function getConnectionName()
    {
        return config('fleetbase.connection.db', 'mysql');
    }
}
