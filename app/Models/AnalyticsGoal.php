<?php

namespace App\Models;

use App\Models\Concerns\StampsSsoTeam;
use Illuminate\Database\Eloquent\Model;

/**
 * 内容生产目标（docs/0903/dashboard/06 提案）。目标必须归属租户；
 * category_id 为空表示租户全局档。月份为 'YYYY-MM'。
 */
class AnalyticsGoal extends Model
{
    use StampsSsoTeam;

    protected $fillable = [
        'sso_team_id',
        'category_id',
        'month',
        'metric',
        'target',
    ];

    protected $casts = [
        'category_id' => 'integer',
        'target' => 'integer',
    ];
}
