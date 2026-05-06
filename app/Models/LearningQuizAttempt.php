<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LearningQuizAttempt extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'learning_quiz_id',
        'score',
        'passed',
        'lesson_content_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'passed' => 'boolean',
            'lesson_content_version' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(LearningQuiz::class, 'learning_quiz_id');
    }

    /**
     * @return HasMany<LearningQuizResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(LearningQuizResponse::class, 'learning_quiz_attempt_id');
    }
}
