<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportBatchFile extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED_DUPLICATE = 'skipped_duplicate';

    public const STATUS_SKIPPED_UNSUPPORTED = 'skipped_unsupported';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'import_batch_id', 'relative_path', 'original_filename',
        'size_bytes', 'sha256', 'status', 'thought_id',
        'error_code', 'error_message', 'attempts', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'int',
            'attempts' => 'int',
            'processed_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function thought(): BelongsTo
    {
        return $this->belongsTo(Thought::class);
    }
}
