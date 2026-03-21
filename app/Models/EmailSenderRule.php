<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailSenderRule extends Model
{
    public const ACTION_ALLOW = 'allow';

    public const ACTION_IGNORE = 'ignore';

    public const ACTION_REVIEW = 'review';

    public const ACTION_EXTRA_PROCESS = 'extra_process';

    protected $fillable = [
        'user_id',
        'sender_email',
        'action',
    ];

    /**
     * @return list<string>
     */
    public static function actions(): array
    {
        return [
            self::ACTION_ALLOW,
            self::ACTION_IGNORE,
            self::ACTION_REVIEW,
            self::ACTION_EXTRA_PROCESS,
        ];
    }

    public static function isValidAction(string $action): bool
    {
        return in_array($action, self::actions(), true);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Normalise sender email to lowercase and trim before saving.
     */
    protected static function booted(): void
    {
        static::saving(function (EmailSenderRule $model): void {
            if ($model->sender_email !== null) {
                $model->sender_email = mb_strtolower(trim($model->sender_email));
            }
        });
    }
}
