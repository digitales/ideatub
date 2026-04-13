<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThoughtLink extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'from_thought_id',
        'to_thought_id',
        'link_type',
        'note',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fromThought(): BelongsTo
    {
        return $this->belongsTo(Thought::class, 'from_thought_id');
    }

    public function toThought(): BelongsTo
    {
        return $this->belongsTo(Thought::class, 'to_thought_id');
    }
}
