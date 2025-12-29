<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class DataSource extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'config',
        'oauth_token',
        'oauth_refresh_token',
        'oauth_token_expires_at',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'oauth_token_expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the GitHub data for this data source.
     */
    public function githubData(): HasMany
    {
        return $this->hasMany(GitHubData::class, 'source_id');
    }

    /**
     * Get the Strava data for this data source.
     */
    public function stravaData(): HasMany
    {
        return $this->hasMany(StravaData::class, 'source_id');
    }

    /**
     * Get the ingestion logs for this data source.
     */
    public function ingestionLogs(): HasMany
    {
        return $this->hasMany(IngestionLog::class, 'source_id');
    }

    /**
     * Encrypt and set the OAuth token.
     */
    public function setOauthTokenAttribute(?string $value): void
    {
        $this->attributes['oauth_token'] = $value ? Crypt::encryptString($value) : null;
    }

    /**
     * Decrypt and get the OAuth token.
     */
    public function getOauthTokenAttribute(?string $value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    /**
     * Encrypt and set the OAuth refresh token.
     */
    public function setOauthRefreshTokenAttribute(?string $value): void
    {
        $this->attributes['oauth_refresh_token'] = $value ? Crypt::encryptString($value) : null;
    }

    /**
     * Decrypt and get the OAuth refresh token.
     */
    public function getOauthRefreshTokenAttribute(?string $value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    /**
     * Check if the OAuth token is expired.
     */
    public function isTokenExpired(): bool
    {
        if (!$this->oauth_token_expires_at) {
            return false;
        }

        return $this->oauth_token_expires_at->isPast();
    }

    /**
     * Check if the token needs refresh (expires within 5 minutes).
     */
    public function needsTokenRefresh(): bool
    {
        if (!$this->oauth_token_expires_at) {
            return false;
        }

        return $this->oauth_token_expires_at->subMinutes(5)->isPast();
    }
}

