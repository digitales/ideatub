<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LearningQuiz extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'learning_lesson_id',
        'title',
        'passing_score',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'passing_score' => 'integer',
        ];
    }

    public function learningLesson(): BelongsTo
    {
        return $this->belongsTo(LearningLesson::class);
    }

    /**
     * @return HasMany<LearningQuizQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(LearningQuizQuestion::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<LearningQuizAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(LearningQuizAttempt::class);
    }
}
