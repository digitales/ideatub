<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserInboundAddress extends Model
{
    protected $fillable = [
        'user_id',
        'email',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Normalise email to lowercase and trim before saving.
     */
    protected static function booted(): void
    {
        static::saving(function (UserInboundAddress $model): void {
            $model->email = mb_strtolower(trim($model->email));
        });
    }
}
