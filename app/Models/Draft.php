<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Draft extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    const UPDATED_AT = 'updated_at';

    const CREATED_AT = null;

    protected $fillable = ['user_id', 'content', 'no_chunking'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'no_chunking' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Preview for list: first line or first 60 chars, or first 57 chars + '...' if longer.
     */
    public function getContentPreviewAttribute(): string
    {
        $text = (string) ($this->content ?? '');
        $trimmed = trim($text);
        $firstLine = trim((string) (explode("\n", $text)[0] ?? ''));
        $preview = $firstLine !== '' ? $firstLine : mb_substr($trimmed, 0, 60);
        if (mb_strlen($preview) <= 60) {
            return $preview;
        }

        return mb_substr($preview, 0, 57) . '...';
    }
}
