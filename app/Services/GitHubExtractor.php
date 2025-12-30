<?php

namespace App\Services;

use App\Models\DataSource;
use App\Models\GitHubData;
use App\Schemas\GitHubDatasetSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GitHub Data Extractor
 *
 * Extracts data from GitHub API using Personal Access Token authentication.
 * Supports fetching repository events, commits, issues, and pull requests.
 */
class GitHubExtractor implements DataExtractorInterface
{
    private GitHubDatasetSchema $schema;

    public function __construct(GitHubDatasetSchema $schema)
    {
        $this->schema = $schema;
    }

    /**
     * Extract data from GitHub API
     *
     * @param DataSource $dataSource
     * @param array<string, mixed> $options
     * @return array{success: bool, records_extracted: int, message: string}
     */
    public function extract(DataSource $dataSource, array $options = []): array
    {
        $config = Config::get('integrations.github');

        if (!$config['enabled']) {
            return [
                'success' => false,
                'records_extracted' => 0,
                'message' => 'GitHub integration is disabled',
            ];
        }

        $token = $config['personal_access_token'] ?? $dataSource->config['personal_access_token'] ?? null;

        if (!$token) {
            return [
                'success' => false,
                'records_extracted' => 0,
                'message' => 'GitHub Personal Access Token is not configured',
            ];
        }

        try {
            // Determine what data to extract
            $extractType = $options['type'] ?? 'events'; // events, commits, issues, pull_requests
            $repository = $options['repository'] ?? null;
            // Default to 2 years ago to ensure we get a past date
            $since = $options['since'] ?? now()->subYears(2)->toIso8601String();

            $recordsExtracted = 0;

            if ($repository) {
                // Extract from specific repository
                $recordsExtracted = $this->extractFromRepository(
                    $dataSource,
                    $token,
                    $repository,
                    $extractType,
                    $since,
                    $config
                );
            } else {
                // Extract from authenticated user's repositories
                $recordsExtracted = $this->extractFromUserRepositories(
                    $dataSource,
                    $token,
                    $extractType,
                    $since,
                    $config
                );
            }

            return [
                'success' => true,
                'records_extracted' => $recordsExtracted,
                'message' => "Successfully extracted {$recordsExtracted} records from GitHub",
            ];
        } catch (\Exception $e) {
            Log::error('GitHub extraction failed', [
                'source_id' => $dataSource->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'records_extracted' => 0,
                'message' => 'GitHub extraction failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get the name of the extractor
     */
    public function getName(): string
    {
        return 'github';
    }

    /**
     * Extract data from a specific repository
     */
    private function extractFromRepository(
        DataSource $dataSource,
        string $token,
        string $repository,
        string $type,
        string $since,
        array $config
    ): int {
        $recordsExtracted = 0;

        switch ($type) {
            case 'events':
                $recordsExtracted = $this->fetchRepositoryEvents($dataSource, $token, $repository, $since, $config);
                break;
            case 'commits':
                $recordsExtracted = $this->fetchRepositoryCommits($dataSource, $token, $repository, $since, $config);
                break;
            case 'issues':
                $recordsExtracted = $this->fetchRepositoryIssues($dataSource, $token, $repository, $since, $config);
                break;
            case 'pull_requests':
                $recordsExtracted = $this->fetchRepositoryPullRequests($dataSource, $token, $repository, $since, $config);
                break;
        }

        return $recordsExtracted;
    }

    /**
     * Extract data from user's repositories
     */
    private function extractFromUserRepositories(
        DataSource $dataSource,
        string $token,
        string $type,
        string $since,
        array $config
    ): int {
        $totalRecords = 0;

        // Get user's repositories
        $repositories = $this->fetchUserRepositories($token, $config);

        foreach ($repositories as $repo) {
            $repoName = $repo['full_name'];
            $records = $this->extractFromRepository($dataSource, $token, $repoName, $type, $since, $config);
            $totalRecords += $records;

            // Rate limiting: small delay between repositories
            usleep(100000); // 0.1 seconds
        }

        return $totalRecords;
    }

    /**
     * Fetch repository events
     */
    private function fetchRepositoryEvents(
        DataSource $dataSource,
        string $token,
        string $repository,
        string $since,
        array $config
    ): int {
        $url = "{$config['api_url']}/repos/{$repository}/events";
        $page = 1;
        $perPage = 100;
        $totalRecords = 0;

        do {
            try {
                $response = $this->makeRequest($token, $url, $config, [
                    'page' => $page,
                    'per_page' => $perPage,
                ]);

                if (!$response->successful()) {
                    break;
                }

                $events = $response->json();
                if (empty($events)) {
                    break;
                }

                foreach ($events as $event) {
                    // Check if event is within time range
                    $eventDate = $event['created_at'] ?? null;
                    if ($eventDate && strtotime($eventDate) < strtotime($since)) {
                        continue;
                    }

                    // Store raw data
                    GitHubData::create([
                        'source_id' => $dataSource->id,
                        'repository_name' => $repository,
                        'event_type' => $event['type'] ?? 'unknown',
                        'raw_data' => $event,
                        'extracted_at' => now(),
                    ]);

                    $totalRecords++;
                }

                $page++;
            } catch (\Exception $e) {
                Log::warning('Error fetching GitHub events', [
                    'repository' => $repository,
                    'page' => $page,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        } while (count($events ?? []) === $perPage);

        return $totalRecords;
    }

    /**
     * Fetch repository commits
     */
    private function fetchRepositoryCommits(
        DataSource $dataSource,
        string $token,
        string $repository,
        string $since,
        array $config
    ): int {
        $url = "{$config['api_url']}/repos/{$repository}/commits";
        $page = 1;
        $perPage = 100;
        $totalRecords = 0;

        do {
            try {
                $response = $this->makeRequest($token, $url, $config, [
                    'page' => $page,
                    'per_page' => $perPage,
                    'since' => $since,
                ]);

                if (!$response->successful()) {
                    break;
                }

                $commits = $response->json();
                if (empty($commits)) {
                    break;
                }

                foreach ($commits as $commit) {
                    // Store as event type 'push' with commit data
                    GitHubData::create([
                        'source_id' => $dataSource->id,
                        'repository_name' => $repository,
                        'event_type' => 'push',
                        'raw_data' => $commit,
                        'extracted_at' => now(),
                    ]);

                    $totalRecords++;
                }

                $page++;
            } catch (\Exception $e) {
                Log::warning('Error fetching GitHub commits', [
                    'repository' => $repository,
                    'page' => $page,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        } while (count($commits ?? []) === $perPage);

        return $totalRecords;
    }

    /**
     * Fetch repository issues
     */
    private function fetchRepositoryIssues(
        DataSource $dataSource,
        string $token,
        string $repository,
        string $since,
        array $config
    ): int {
        $url = "{$config['api_url']}/repos/{$repository}/issues";
        $page = 1;
        $perPage = 100;
        $totalRecords = 0;

        do {
            try {
                $response = $this->makeRequest($token, $url, $config, [
                    'page' => $page,
                    'per_page' => $perPage,
                    'since' => $since,
                    'state' => 'all',
                ]);

                if (!$response->successful()) {
                    break;
                }

                $issues = $response->json();
                if (empty($issues)) {
                    break;
                }

                foreach ($issues as $issue) {
                    // Store as event type 'issue'
                    GitHubData::create([
                        'source_id' => $dataSource->id,
                        'repository_name' => $repository,
                        'event_type' => 'issue',
                        'raw_data' => $issue,
                        'extracted_at' => now(),
                    ]);

                    $totalRecords++;
                }

                $page++;
            } catch (\Exception $e) {
                Log::warning('Error fetching GitHub issues', [
                    'repository' => $repository,
                    'page' => $page,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        } while (count($issues ?? []) === $perPage);

        return $totalRecords;
    }

    /**
     * Fetch repository pull requests
     */
    private function fetchRepositoryPullRequests(
        DataSource $dataSource,
        string $token,
        string $repository,
        string $since,
        array $config
    ): int {
        $url = "{$config['api_url']}/repos/{$repository}/pulls";
        $page = 1;
        $perPage = 100;
        $totalRecords = 0;

        do {
            try {
                $response = $this->makeRequest($token, $url, $config, [
                    'page' => $page,
                    'per_page' => $perPage,
                    'state' => 'all',
                    'sort' => 'updated',
                    'direction' => 'desc',
                ]);

                if (!$response->successful()) {
                    break;
                }

                $pulls = $response->json();
                if (empty($pulls)) {
                    break;
                }

                foreach ($pulls as $pull) {
                    // Check if PR was updated since the specified date
                    $updatedAt = $pull['updated_at'] ?? null;
                    if ($updatedAt && strtotime($updatedAt) < strtotime($since)) {
                        continue;
                    }

                    // Store as event type 'pull_request'
                    GitHubData::create([
                        'source_id' => $dataSource->id,
                        'repository_name' => $repository,
                        'event_type' => 'pull_request',
                        'raw_data' => $pull,
                        'extracted_at' => now(),
                    ]);

                    $totalRecords++;
                }

                $page++;
            } catch (\Exception $e) {
                Log::warning('Error fetching GitHub pull requests', [
                    'repository' => $repository,
                    'page' => $page,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        } while (count($pulls ?? []) === $perPage);

        return $totalRecords;
    }

    /**
     * Fetch user's repositories
     */
    private function fetchUserRepositories(string $token, array $config): array
    {
        $url = "{$config['api_url']}/user/repos";
        $repositories = [];
        $page = 1;
        $perPage = 100;

        do {
            try {
                $response = $this->makeRequest($token, $url, $config, [
                    'page' => $page,
                    'per_page' => $perPage,
                    'sort' => 'updated',
                    'direction' => 'desc',
                ]);

                if (!$response->successful()) {
                    break;
                }

                $repos = $response->json();
                if (empty($repos)) {
                    break;
                }

                $repositories = array_merge($repositories, $repos);
                $page++;
            } catch (\Exception $e) {
                Log::warning('Error fetching user repositories', [
                    'page' => $page,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        } while (count($repos ?? []) === $perPage);

        return $repositories;
    }

    /**
     * Make HTTP request to GitHub API with rate limiting and error handling
     */
    private function makeRequest(string $token, string $url, array $config, array $params = [])
    {
        // Build full URL with query parameters
        $fullUrl = $url;
        if (!empty($params)) {
            $fullUrl .= '?' . http_build_query($params);
        }

        // On Windows, HTTP client has connection issues, so skip it entirely and use file_get_contents
        // On non-Windows, try HTTP client first, fallback to file_get_contents if it fails
        $useFileGetContents = PHP_OS_FAMILY === 'Windows';
        
        if (!$useFileGetContents) {
            // Try HTTP client on non-Windows systems
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
                        'Authorization' => "token {$token}",
                        'Accept' => 'application/vnd.github.v3+json',
                        'User-Agent' => 'Databox-Integration-Service',
                    ])
                    ->get($url, $params);

                if ($response->successful()) {
                    // Check rate limiting
                    $remaining = (int) $response->header('X-RateLimit-Remaining');
                    $resetAt = (int) $response->header('X-RateLimit-Reset');

                    if ($remaining !== null && $remaining < 10) {
                        $waitTime = max(0, $resetAt - time());
                        if ($waitTime > 0) {
                            Log::warning('GitHub rate limit approaching', [
                                'remaining' => $remaining,
                                'wait_time' => $waitTime,
                            ]);
                            sleep(min($waitTime, 60)); // Wait max 60 seconds
                        }
                    }

                    // Handle rate limit exceeded
                    if ($response->status() === 403 && str_contains($response->body(), 'rate limit')) {
                        throw new \Exception('GitHub API rate limit exceeded');
                    }

                    return $response;
                }
            } catch (\Throwable $e) {
                Log::warning('Laravel HTTP client failed, using file_get_contents fallback', [
                    'error' => $e->getMessage(),
                    'type' => get_class($e),
                ]);
                $useFileGetContents = true;
            }
        }

        // Use file_get_contents (always on Windows, or as fallback on other systems)
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    "Authorization: token {$token}",
                    'Accept: application/vnd.github.v3+json',
                    'User-Agent: Databox-Integration-Service',
                ],
                'timeout' => (int) $config['timeout'],
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ]);

        // On Windows, try system curl command if PHP curl extension is not available
        $result = false;
        $useCurl = function_exists('curl_init');
        
        Log::info('Attempting GitHub API request', [
            'url' => $fullUrl,
            'os' => PHP_OS_FAMILY,
            'curl_available' => $useCurl,
        ]);
        
        // Try PHP curl extension first
        if ($useCurl) {
            Log::info('Using PHP curl extension for GitHub API request');
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $fullUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: token {$token}",
                'Accept: application/vnd.github.v3+json',
                'User-Agent: Databox-Integration-Service',
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, (int) $config['timeout']);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            // curl_close() is deprecated in PHP 8.0+ - resources are automatically closed
            
            if ($result !== false && $httpCode >= 200 && $httpCode < 300) {
                Log::info('PHP curl succeeded', [
                    'url' => $fullUrl,
                    'response_length' => strlen($result),
                    'http_code' => $httpCode,
                ]);
            } else {
                Log::warning('PHP curl failed, will try system curl', [
                    'url' => $fullUrl,
                    'error' => $curlError,
                    'http_code' => $httpCode,
                ]);
                $result = false;
            }
        }
        
        // Try system curl command (works on Windows when PHP curl extension is not available)
        if ($result === false && PHP_OS_FAMILY === 'Windows') {
            Log::info('Trying system curl command');
            $escapedUrl = escapeshellarg($fullUrl);
            $escapedToken = escapeshellarg($token);
            
            // Build curl command
            $curlCmd = "curl -s -H \"Authorization: token {$token}\" -H \"Accept: application/vnd.github.v3+json\" -H \"User-Agent: Databox-Integration-Service\" --insecure --max-time " . (int) $config['timeout'] . " {$escapedUrl}";
            
            $result = @shell_exec($curlCmd);
            
            if ($result !== null && $result !== false && strlen($result) > 0) {
                // Check if result is valid JSON
                $testJson = json_decode($result, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    Log::info('System curl succeeded', [
                        'url' => $fullUrl,
                        'response_length' => strlen($result),
                    ]);
                } else {
                    Log::warning('System curl returned invalid JSON', [
                        'url' => $fullUrl,
                        'response_preview' => substr($result, 0, 200),
                    ]);
                    $result = false;
                }
            } else {
                Log::warning('System curl failed or returned empty');
                $result = false;
            }
        }
        
        // Fallback to file_get_contents if curl failed or not available
        if ($result === false) {
            // Check if allow_url_fopen is enabled
            if (!ini_get('allow_url_fopen')) {
                Log::error('allow_url_fopen is disabled in php.ini');
                throw new \Exception('allow_url_fopen is disabled. Please enable it in php.ini');
            }
            
            Log::info('Trying file_get_contents as fallback');
            $result = @file_get_contents($fullUrl, false, $context);
            
            if ($result === false) {
                $error = error_get_last();
                $errorMsg = $error['message'] ?? 'Unknown error';
                Log::error('file_get_contents failed', [
                    'url' => $fullUrl,
                    'error' => $errorMsg,
                    'allow_url_fopen' => ini_get('allow_url_fopen'),
                    'php_version' => PHP_VERSION,
                ]);
                throw new \Exception('Failed to connect to GitHub API: ' . $errorMsg);
            }
            
            Log::info('file_get_contents succeeded', [
                'url' => $fullUrl, 
                'response_length' => strlen($result),
            ]);
        }
        
        Log::info('file_get_contents succeeded', [
            'url' => $fullUrl, 
            'response_length' => strlen($result),
            'first_100_chars' => substr($result, 0, 100)
        ]);

        // Parse response headers
        $statusCode = 200;
        $responseHeaders = [];
        
        // Use http_get_last_response_headers() for PHP 8.2+ (replaces deprecated $http_response_header)
        $headers = function_exists('http_get_last_response_headers') 
            ? http_get_last_response_headers() 
            : ($http_response_header ?? []);
        
        if (!empty($headers)) {
            // Parse status code from first header
            if (isset($headers[0])) {
                preg_match('/HTTP\/\d\.\d\s+(\d+)/', $headers[0], $matches);
                $statusCode = isset($matches[1]) ? (int) $matches[1] : 200;
            }
            
            // Parse all headers
            foreach ($headers as $header) {
                if (stripos($header, 'X-RateLimit-Remaining:') === 0) {
                    $remaining = (int) trim(substr($header, 23));
                    $responseHeaders['X-RateLimit-Remaining'] = $remaining;
                    if ($remaining < 10) {
                        Log::warning('GitHub rate limit approaching', [
                            'remaining' => $remaining,
                        ]);
                    }
                } elseif (stripos($header, 'X-RateLimit-Reset:') === 0) {
                    $responseHeaders['X-RateLimit-Reset'] = trim(substr($header, 19));
                }
            }
        }

        if ($statusCode === 403) {
            throw new \Exception('GitHub API rate limit exceeded');
        }

        // Create a proper response object that mimics Laravel's HTTP response
        return new class($result, $statusCode, $responseHeaders) {
            private $body;
            private $status;
            private $headers;

            public function __construct($body, $status, $headers = [])
            {
                $this->body = $body;
                $this->status = $status;
                $this->headers = $headers;
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

            public function header(string $name): ?string
            {
                return $this->headers[$name] ?? null;
            }
        };
    }
}

