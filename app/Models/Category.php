<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    public const UPDATED_AT = null;
    use \App\Models\Concerns\StampsSsoTeam;

    protected $table = 'categories';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'sort_order',
        'sso_team_id',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class, 'category_id');
    }

    public function articlesIncludingTrashed(): HasMany
    {
        return $this->hasMany(Article::class, 'category_id')->withTrashed();
    }
}
