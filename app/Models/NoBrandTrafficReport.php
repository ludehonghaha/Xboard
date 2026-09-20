<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NoBrandTrafficReport extends Model
{
    protected $table = 'v2_nobrand_traffic_report';

    protected $guarded = ['id'];

    protected $casts = [
        'machine_id' => 'integer',
        'server_id' => 'integer',
        'readings' => 'array',
        'observed_at' => 'integer',
        'delta_u' => 'integer',
        'delta_d' => 'integer',
        'processed_at' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function machine(): BelongsTo
    {
        return $this->belongsTo(ServerMachine::class, 'machine_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }
}
