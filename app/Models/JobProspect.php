<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobProspect extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUSES = ['new', 'scored', 'shortlisted', 'dismissed', 'promoted'];

    public const SOURCES = ['linkedin', 'job_board', 'referral', 'direct'];

    protected $fillable = [
        'user_id', 'company', 'role_title', 'source', 'url', 'salary_signal',
        'fit_score', 'status', 'discovered_at', 'scored_at', 'notes', 'promoted_application_id',
    ];

    protected function casts(): array
    {
        return [
            'fit_score' => 'integer',
            'discovered_at' => 'datetime',
            'scored_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function promotedApplication(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'promoted_application_id');
    }
}
