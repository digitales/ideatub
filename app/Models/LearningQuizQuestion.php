<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearningQuizQuestion extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'learning_quiz_id',
        'sort_order',
        'prompt',
        'options',
        'correct_option_index',
        'explanation',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'options' => 'array',
            'correct_option_index' => 'integer',
        ];
    }

    public function learningQuiz(): BelongsTo
    {
        return $this->belongsTo(LearningQuiz::class);
    }
}
