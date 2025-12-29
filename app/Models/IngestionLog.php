<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngestionLog extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'source_id',
        'dataset_name',
        'rows_sent',
        'columns_sent',
        'status',
        'error_message',
        'sent_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    /**
     * Get the data source that owns this ingestion log.
     */
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'source_id');
    }

    /**
     * Check if the ingestion was successful.
     */
    public function isSuccessful(): bool
    {
        return $this->status === 'success';
    }

    /**
     * Check if the ingestion failed.
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}

