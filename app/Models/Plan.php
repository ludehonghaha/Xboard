<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use InvalidArgumentException;

/**
 * Xboard Lite service profile.
 *
 * A plan is an administrator-assigned service template, not a product.
 * It defines traffic, limits, node group and reset behaviour only.
 */
class Plan extends Model
{
    use HasFactory;

    protected $table = 'v2_plan';
    protected $dateFormat = 'U';

    public const RESET_TRAFFIC_FOLLOW_SYSTEM = null;
    public const RESET_TRAFFIC_FIRST_DAY_MONTH = 0;
    public const RESET_TRAFFIC_MONTHLY = 1;
    public const RESET_TRAFFIC_NEVER = 2;
    public const RESET_TRAFFIC_FIRST_DAY_YEAR = 3;
    public const RESET_TRAFFIC_YEARLY = 4;

    protected $fillable = [
        'group_id',
        'transfer_enable',
        'name',
        'speed_limit',
        'show',
        'sort',
        'content',
        'reset_traffic_method',
        'capacity_limit',
        'device_limit',
        'tags',
    ];

    protected $casts = [
        'show' => 'boolean',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'group_id' => 'integer',
        'tags' => 'array',
        'reset_traffic_method' => 'integer',
    ];

    public static function getResetTrafficMethods(): array
    {
        return [
            self::RESET_TRAFFIC_FOLLOW_SYSTEM => '跟随系统设置',
            self::RESET_TRAFFIC_FIRST_DAY_MONTH => '每月1号',
            self::RESET_TRAFFIC_MONTHLY => '按月重置',
            self::RESET_TRAFFIC_NEVER => '不重置',
            self::RESET_TRAFFIC_FIRST_DAY_YEAR => '每年1月1日',
            self::RESET_TRAFFIC_YEARLY => '按年重置',
        ];
    }

    /**
     * In Lite mode traffic reset is a service-policy decision, not a paid action.
     */
    public function canResetTraffic(): bool
    {
        return $this->reset_traffic_method !== self::RESET_TRAFFIC_NEVER;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function group(): HasOne
    {
        return $this->hasOne(ServerGroup::class, 'id', 'group_id');
    }

    public function setResetTrafficMethod(?int $method): void
    {
        if (!array_key_exists($method, self::getResetTrafficMethods())) {
            throw new InvalidArgumentException("Invalid reset traffic method: {$method}");
        }

        $this->reset_traffic_method = $method;
    }
}
