<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LearningLesson extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'learning_project_id',
        'slug',
        'title',
        'stage',
        'difficulty',
        'order',
        'summary',
        'goals',
        'related_research_slugs',
        'body_markdown',
        'content_version',
        'synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'goals' => 'array',
            'related_research_slugs' => 'array',
            'content_version' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function learningProject(): BelongsTo
    {
        return $this->belongsTo(LearningProject::class);
    }

    public function quiz(): HasOne
    {
        return $this->hasOne(LearningQuiz::class);
    }

    /**
     * @return HasMany<LearningLessonProgress, $this>
     */
    public function progressRecords(): HasMany
    {
        return $this->hasMany(LearningLessonProgress::class, 'learning_lesson_id');
    }

    /**
     * @return HasMany<LearningLessonNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(LearningLessonNote::class, 'learning_lesson_id');
    }
}
