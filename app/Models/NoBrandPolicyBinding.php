<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NoBrandPolicyBinding extends Model
{
    protected $table = 'v2_nobrand_policy_binding';

    protected $guarded = ['id'];

    protected $casts = [
        'machine_id' => 'integer',
        'user_id' => 'integer',
        'quota_days' => 'integer',
        'sync_enabled' => 'boolean',
        'last_remote_state' => 'array',
        'last_applied_at' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function machine(): BelongsTo
    {
        return $this->belongsTo(ServerMachine::class, 'machine_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
