<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Interaction extends Model
{
    use HasFactory;
    use HasUuids;

    public const TYPES = ['interview', 'follow_up', 'rejection', 'offer', 'note'];

    protected $fillable = ['user_id', 'application_id', 'type', 'occurred_at', 'summary', 'debrief_thought_id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function debriefThought(): BelongsTo
    {
        return $this->belongsTo(Thought::class, 'debrief_thought_id');
    }
}
