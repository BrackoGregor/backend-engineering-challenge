<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GitHubData extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'source_id',
        'repository_name',
        'event_type',
        'raw_data',
        'extracted_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raw_data' => 'array',
            'extracted_at' => 'datetime',
        ];
    }

    /**
     * Get the data source that owns this GitHub data.
     */
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'source_id');
    }
}

