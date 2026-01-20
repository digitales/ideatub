<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Operation extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'operation_type',
    ];

    /**
     * Get the user that owns the operation.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
