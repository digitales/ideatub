<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearningQuizResponse extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'learning_quiz_attempt_id',
        'learning_quiz_question_id',
        'selected_option_index',
        'correct',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'selected_option_index' => 'integer',
            'correct' => 'boolean',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(LearningQuizAttempt::class, 'learning_quiz_attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(LearningQuizQuestion::class, 'learning_quiz_question_id');
    }
}
