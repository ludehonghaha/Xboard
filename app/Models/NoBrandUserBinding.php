<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NoBrandUserBinding extends Model
{
    protected $table = 'v2_nobrand_user_binding';

    protected $guarded = ['id'];

    protected $hidden = ['credential_payload'];

    protected $casts = [
        'display_port' => 'integer',
        'enabled' => 'boolean',
        'runtime_meta' => 'array',
        'credential_payload' => 'encrypted:array',
        'last_synced_at' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
