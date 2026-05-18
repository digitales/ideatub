<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends Model
{
    /** Global toggle: allow research to auto-run when eligible skills exist. Stored as JSON boolean. */
    public const KEY_RESEARCH_AUTO_RUN_ENABLED = 'research_auto_run_enabled';

    /** Global toggle: allow meeting processing to auto-run when eligible default skills exist. Stored as JSON boolean. */
    public const KEY_MEETING_AUTO_RUN_ENABLED = 'meeting_auto_run_enabled';

    public const KEY_WORKING_MEMORY_CONSOLIDATION_WINDOW_DAYS = 'working_memory_consolidation_window_days';

    public const KEY_WORKING_MEMORY_FORCED_TAGS = 'working_memory_forced_tags';

    /** UI appearance: light, dark, or system. Stored as JSON string. */
    public const KEY_APPEARANCE = 'appearance';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'key',
        'value',
    ];

    /**
     * Get the user that owns the preference.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get a preference value for a user.
     *
     * @param  mixed  $default  Value to return if the preference does not exist.
     */
    public static function get(User $user, string $key, mixed $default = null): mixed
    {
        $row = static::query()
            ->where('user_id', $user->id)
            ->where('key', $key)
            ->first();

        if ($row === null) {
            return $default;
        }

        $value = $row->value;
        if ($value === null || $value === '') {
            return $default;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $value;
    }

    /**
     * Set a preference value for a user (create or update).
     *
     * @param  mixed  $value  String stored as-is; other types JSON-encoded.
     */
    public static function set(User $user, string $key, mixed $value): self
    {
        $stored = is_string($value) ? $value : json_encode($value);

        return static::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'key' => $key,
            ],
            ['value' => $stored]
        );
    }
}
