<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_COMPLETED_WITH_FAILURES = 'completed_with_failures';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id', 'project_id', 'root_folder_name', 'source',
        'status', 'file_count', 'total_bytes',
        'processed_count', 'failed_count', 'skipped_count',
        'no_chunking', 'skip_ai_metadata', 'options',
        'staging_path', 'laravel_batch_id', 'completion_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'file_count' => 'int',
            'total_bytes' => 'int',
            'processed_count' => 'int',
            'failed_count' => 'int',
            'skipped_count' => 'int',
            'no_chunking' => 'bool',
            'skip_ai_metadata' => 'bool',
            'options' => 'array',
            'completion_notified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<ImportBatchFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(ImportBatchFile::class);
    }

    public function isMicrositeImport(): bool
    {
        return data_get($this->options, 'import_kind') === 'microsite';
    }

    public function localAssetRefCount(): int
    {
        return (int) data_get($this->options, 'local_asset_ref_count', 0);
    }
}
