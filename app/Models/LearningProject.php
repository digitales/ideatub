<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LearningProject extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'slug',
        'title',
        'content_root',
        'source_url',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<LearningResearchDocument, $this>
     */
    public function researchDocuments(): HasMany
    {
        return $this->hasMany(LearningResearchDocument::class);
    }

    /**
     * @return HasMany<LearningLesson, $this>
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(LearningLesson::class);
    }
}
