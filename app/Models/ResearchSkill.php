<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ResearchSkill extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'description',
        'is_manual_enabled',
        'allow_auto_run',
        'is_default',
        'is_active',
        'latest_version_number',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_manual_enabled' => 'boolean',
            'allow_auto_run' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'latest_version_number' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ResearchSkillVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ResearchSkillVersion::class);
    }

    /**
     * @return HasOne<ResearchSkillVersion, $this>
     */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(ResearchSkillVersion::class)->latestOfMany('version');
    }

    /**
     * @return HasMany<ResearchRun, $this>
     */
    public function researchRuns(): HasMany
    {
        return $this->hasMany(ResearchRun::class);
    }
}
