<?php

namespace App\Services;

use App\Models\DataSource;
use App\Models\StravaData;
use App\Schemas\StravaDatasetSchema;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Strava Data Extractor
 *
 * Extracts activity data from Strava API using OAuth2 authentication.
 * Handles token refresh automatically when tokens expire.
 */
class StravaExtractor implements DataExtractorInterface
{
    public function __construct(
        private StravaDatasetSchema $schema,
        private StravaOAuthService $oauthService
    ) {}

    /**
     * Extract data from Strava API
     *
     * @param DataSource $dataSource
     * @param array<string, mixed> $options
     * @return array{success: bool, records_extracted: int, message: string}
     */
    public function extract(DataSource $dataSource, array $options = []): array
    {
        $config = Config::get('integrations.strava');

        if (!$config['enabled']) {
            return [
                'success' => false,
                'records_extracted' => 0,
                'message' => 'Strava integration is disabled',
            ];
        }

        // Check if we have OAuth tokens
        if (!$dataSource->oauth_token) {
            return [
                'success' => false,
                'records_extracted' => 0,
                'message' => 'Strava OAuth tokens not configured. Please authorize the integration first.',
            ];
        }

        try {
            // Refresh token if needed
            if ($dataSource->needsTokenRefresh()) {
                Log::info('Refreshing Strava token', ['source_id' => $dataSource->id]);
                $this->oauthService->refreshToken($dataSource);
                $dataSource->refresh(); // Reload from database
            }

            // Determine date range
            $before = $options['before'] ?? now()->toIso8601String();
            $after = $options['after'] ?? now()->subDays(30)->toIso8601String();
            $perPage = $options['per_page'] ?? 200;

            $recordsExtracted = $this->fetchActivities(
                $dataSource,
                $config,
                $after,
                $before,
                $perPage
            );

            return [
                'success' => true,
                'records_extracted' => $recordsExtracted,
                'message' => "Successfully extracted {$recordsExtracted} activities from Strava",
            ];
        } catch (\Exception $e) {
            Log::error('Strava extraction failed', [
                'source_id' => $dataSource->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'records_extracted' => 0,
                'message' => 'Strava extraction failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get the name of the extractor
     */
    public function getName(): string
    {
        return 'strava';
    }

    /**
     * Fetch activities from Strava API
     */
    private function fetchActivities(
        DataSource $dataSource,
        array $config,
        string $after,
        string $before,
        int $perPage
    ): int {
        $url = "{$config['api_url']}/athlete/activities";
        $page = 1;
        $totalRecords = 0;
        $token = $dataSource->oauth_token;

        do {
            try {
                $response = $this->makeRequest($token, $url, $config, [
                    'page' => $page,
                    'per_page' => min($perPage, 200), // Strava max is 200
                    'after' => strtotime($after),
                    'before' => strtotime($before),
                ]);

                if (!$response->successful()) {
                    // Check if token expired
                    if ($response->status() === 401) {
                        Log::info('Token expired, attempting refresh', ['source_id' => $dataSource->id]);
                        $this->oauthService->refreshToken($dataSource);
                        $dataSource->refresh();
                        $token = $dataSource->oauth_token;
                        
                        // Retry the request
                        $response = $this->makeRequest($token, $url, $config, [
                            'page' => $page,
                            'per_page' => min($perPage, 200),
                            'after' => strtotime($after),
                            'before' => strtotime($before),
                        ]);
                    } else {
                        break;
                    }
                }

                $activities = $response->json();
                if (empty($activities) || !is_array($activities)) {
                    break;
                }

                foreach ($activities as $activity) {
                    // Check if activity already exists
                    $exists = StravaData::where('activity_id', $activity['id'] ?? null)
                        ->where('source_id', $dataSource->id)
                        ->exists();

                    if (!$exists) {
                        // Store raw data
                        StravaData::create([
                            'source_id' => $dataSource->id,
                            'activity_id' => $activity['id'] ?? null,
                            'raw_data' => $activity,
                            'extracted_at' => now(),
                        ]);

                        $totalRecords++;
                    }
                }

                $page++;
            } catch (\Exception $e) {
                Log::warning('Error fetching Strava activities', [
                    'page' => $page,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        } while (count($activities ?? []) === min($perPage, 200));

        return $totalRecords;
    }

    /**
     * Make HTTP request to Strava API with Windows compatibility
     */
    private function makeRequest(string $token, string $url, array $config, array $params = [])
    {
        // Build full URL with query parameters
        $fullUrl = $url;
        if (!empty($params)) {
            $fullUrl .= '?' . http_build_query($params);
        }

        // On Windows, prefer curl if available
        $result = false;
        $useCurl = PHP_OS_FAMILY === 'Windows' && function_exists('curl_init');
        
        Log::info('Attempting Strava API request', [
            'url' => $fullUrl,
            'os' => PHP_OS_FAMILY,
            'curl_available' => $useCurl,
        ]);
        
        // Try PHP curl extension first
        if ($useCurl) {
            Log::info('Using PHP curl extension for Strava API request');
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $fullUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer {$token}",
                'Accept: application/json',
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, (int) $config['timeout']);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            
            if ($result !== false && $httpCode >= 200 && $httpCode < 300) {
                Log::info('PHP curl succeeded', [
                    'url' => $fullUrl,
                    'response_length' => strlen($result),
                    'http_code' => $httpCode,
                ]);
            } else {
                Log::warning('PHP curl failed, will try HTTP client', [
                    'url' => $fullUrl,
                    'error' => $curlError,
                    'http_code' => $httpCode,
                ]);
                $result = false;
            }
        }
        
        // Fallback to Laravel HTTP client
        if ($result === false) {
            try {
                $response = Http::timeout($config['timeout'])
                    ->withoutVerifying()
                    ->withOptions([
                        'verify' => false,
                        'curl' => [
                            CURLOPT_SSL_VERIFYPEER => false,
                            CURLOPT_SSL_VERIFYHOST => false,
                            CURLOPT_CONNECTTIMEOUT => 10,
                            CURLOPT_TIMEOUT => (int) $config['timeout'],
                        ]
                    ])
                    ->withHeaders([
                        'Authorization' => "Bearer {$token}",
                        'Accept' => 'application/json',
                    ])
                    ->get($url, $params);

                if ($response->successful()) {
                    return $response;
                }
            } catch (\Throwable $e) {
                Log::warning('Laravel HTTP client failed, using system curl', [
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            // Create response wrapper for curl result
            return new class($result, $httpCode ?? 200) {
                private $body;
                private $status;

                public function __construct($body, $status)
                {
                    $this->body = $body;
                    $this->status = $status;
                }

                public function successful(): bool
                {
                    return $this->status >= 200 && $this->status < 300;
                }

                public function status(): int
                {
                    return $this->status;
                }

                public function json(): array
                {
                    $decoded = json_decode($this->body, true);
                    return $decoded ?? [];
                }

                public function body(): string
                {
                    return $this->body;
                }
            };
        }

        // Final fallback: system curl command
        if (PHP_OS_FAMILY === 'Windows') {
            Log::info('Trying system curl command');
            $escapedUrl = escapeshellarg($fullUrl);
            $escapedToken = escapeshellarg($token);
            
            $curlCmd = "curl -s -H \"Authorization: Bearer {$token}\" -H \"Accept: application/json\" --insecure --max-time " . (int) $config['timeout'] . " {$escapedUrl}";
            
            $result = @shell_exec($curlCmd);
            
            if ($result !== null && $result !== false && strlen($result) > 0) {
                $testJson = json_decode($result, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    Log::info('System curl succeeded', [
                        'url' => $fullUrl,
                        'response_length' => strlen($result),
                    ]);
                    
                    return new class($result, 200) {
                        private $body;
                        private $status;

                        public function __construct($body, $status)
                        {
                            $this->body = $body;
                            $this->status = $status;
                        }

                        public function successful(): bool
                        {
                            return $this->status >= 200 && $this->status < 300;
                        }

                        public function status(): int
                        {
                            return $this->status;
                        }

                        public function json(): array
                        {
                            $decoded = json_decode($this->body, true);
                            return $decoded ?? [];
                        }

                        public function body(): string
                        {
                            return $this->body;
                        }
                    };
                }
            }
        }

        throw new \Exception('Failed to connect to Strava API');
    }
}

