<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Application extends Model
{
    use HasFactory;
    use HasUuids;

    public const STAGES = ['researching', 'applied', 'screening', 'interviewing', 'offer', 'rejected', 'withdrawn'];

    protected $fillable = [
        'user_id', 'company_id', 'job_prospect_id', 'role_title', 'stage', 'source',
        'salary_min', 'salary_max', 'applied_at', 'last_activity_at', 'research_thought_id',
        'job_posting_thought_id', 'outcome_thought_id',
        'cv_markdown', 'cover_letter_markdown', 'cv_pdf_path', 'cover_letter_pdf_path',
        'cv_exported_at', 'cover_letter_exported_at',
    ];

    protected function casts(): array
    {
        return [
            'applied_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'cv_exported_at' => 'datetime',
            'cover_letter_exported_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function jobProspect(): BelongsTo
    {
        return $this->belongsTo(JobProspect::class);
    }

    public function researchThought(): BelongsTo
    {
        return $this->belongsTo(Thought::class, 'research_thought_id');
    }

    public function jobPostingThought(): BelongsTo
    {
        return $this->belongsTo(Thought::class, 'job_posting_thought_id');
    }

    public function outcomeThought(): BelongsTo
    {
        return $this->belongsTo(Thought::class, 'outcome_thought_id');
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(Interaction::class)->orderByDesc('occurred_at');
    }
}
