<?php

namespace App\Services;

use App\Models\DataSource;
use App\Models\IngestionLog;
use App\Schemas\SchemaInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Databox Ingestion Service
 *
 * Sends transformed data to Databox Ingestion API.
 * Handles batch sending, retries, and logging.
 */
class DataboxIngestionService
{
    /**
     * Send data to Databox
     *
     * @param DataSource $dataSource
     * @param SchemaInterface $schema
     * @param array<int, array<string, mixed>> $dataRows Array of transformed data rows
     * @param string|null $datasetId Optional dataset ID (UUID string). If not provided, uses config or attempts to find by name
     * @return array{success: bool, rows_sent: int, message: string}
     */
    public function send(DataSource $dataSource, SchemaInterface $schema, array $dataRows, ?string $datasetId = null): array
    {
        $config = Config::get('databox');

        if (empty($dataRows)) {
            return [
                'success' => false,
                'rows_sent' => 0,
                'message' => 'No data to send',
            ];
        }

        $datasetName = $schema->getDatasetName();
        $columns = array_keys($schema->getFields());
        $rowsCount = count($dataRows);

        // Get dataset ID - use provided, or from config, or try to find by name
        if ($datasetId === null) {
            // Try to get from config based on source name
            $datasetId = $config['dataset_id_' . strtolower($dataSource->name)] ?? $config['dataset_id'] ?? null;
        }

        if ($datasetId === null) {
            return [
                'success' => false,
                'rows_sent' => 0,
                'message' => 'Dataset ID not provided. Please provide dataset ID as 4th parameter or set DATABOX_DATASET_ID in config.',
            ];
        }

        try {
            // Prepare payload for Databox API
            $payload = $this->preparePayload($schema, $dataRows);

            // Build the endpoint URL: /v1/datasets/{datasetId}/data
            $baseUrl = rtrim($config['api_url'], '/');
            $endpoint = "{$baseUrl}/datasets/{$datasetId}/data";

            // Log what we're sending (for debugging)
            $payloadPreview = [];
            if (isset($payload['records']) && !empty($payload['records'])) {
                $firstRecord = $payload['records'][0];
                $payloadPreview = array_map(function($value) {
                    if (is_array($value) || is_object($value)) {
                        return gettype($value);
                    }
                    return is_scalar($value) ? (strlen((string)$value) > 50 ? substr((string)$value, 0, 50) . '...' : $value) : gettype($value);
                }, $firstRecord);
            }
            
            Log::info('Sending data to Databox', [
                'endpoint' => $endpoint,
                'dataset_id' => $datasetId,
                'dataset' => $datasetName,
                'rows' => $rowsCount,
                'payload_keys' => array_keys($payload),
                'first_record_preview' => $payloadPreview,
            ]);

            // Send to Databox with retry logic
            $response = $this->sendWithRetry($config, $payload, $endpoint);

            if ($response['success']) {
                // Log successful ingestion
                IngestionLog::create([
                    'source_id' => $dataSource->id,
                    'dataset_name' => $datasetName,
                    'rows_sent' => $rowsCount,
                    'columns_sent' => count($columns),
                    'status' => 'success',
                    'sent_at' => now(),
                ]);

                Log::info('Data sent to Databox successfully', [
                    'source_id' => $dataSource->id,
                    'dataset' => $datasetName,
                    'rows' => $rowsCount,
                ]);

                return [
                    'success' => true,
                    'rows_sent' => $rowsCount,
                    'message' => "Successfully sent {$rowsCount} rows to Databox",
                ];
            } else {
                // Log failed ingestion
                IngestionLog::create([
                    'source_id' => $dataSource->id,
                    'dataset_name' => $datasetName,
                    'rows_sent' => $rowsCount,
                    'columns_sent' => count($columns),
                    'status' => 'failed',
                    'error_message' => $response['error'] ?? 'Unknown error',
                    'sent_at' => now(),
                ]);

                return [
                    'success' => false,
                    'rows_sent' => 0,
                    'message' => 'Failed to send data to Databox: ' . ($response['error'] ?? 'Unknown error'),
                ];
            }
        } catch (\Exception $e) {
            Log::error('Databox ingestion error', [
                'source_id' => $dataSource->id,
                'dataset' => $datasetName,
                'error' => $e->getMessage(),
            ]);

            // Log failed ingestion
            IngestionLog::create([
                'source_id' => $dataSource->id,
                'dataset_name' => $datasetName,
                'rows_sent' => 0,
                'columns_sent' => count($columns),
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'sent_at' => now(),
            ]);

            return [
                'success' => false,
                'rows_sent' => 0,
                'message' => 'Databox ingestion failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Prepare payload for Databox API
     *
     * Databox v1 API expects data in this format:
     * {
     *   "records": [
     *     { "field1": "value1", "field2": "value2", ... },
     *     ...
     *   ]
     * }
     *
     * This method also ensures proper data types are sent (integers, floats, booleans)
     * instead of strings, so Databox can properly create metrics with SUM, AVG, etc.
     *
     * @param SchemaInterface $schema
     * @param array<int, array<string, mixed>> $dataRows
     * @return array<string, mixed>
     */
    private function preparePayload(SchemaInterface $schema, array $dataRows): array
    {
        $fields = $schema->getFields();
        $typedRows = [];

        foreach ($dataRows as $index => $row) {
            try {
                $typedRow = [];
                foreach ($row as $fieldName => $value) {
                    // Get the expected type from schema, default to 'string'
                    $fieldType = $fields[$fieldName]['type'] ?? 'string';
                    try {
                        $typedRow[$fieldName] = $this->convertToType($value, $fieldType);
                    } catch (\Exception $e) {
                        // If type conversion fails, log and use original value
                        Log::warning('Failed to convert field type', [
                            'field' => $fieldName,
                            'type' => $fieldType,
                            'value' => is_scalar($value) ? $value : gettype($value),
                            'error' => $e->getMessage(),
                        ]);
                        $typedRow[$fieldName] = $value;
                    }
                }
                $typedRows[] = $typedRow;
            } catch (\Exception $e) {
                Log::error('Failed to prepare row for payload', [
                    'row_index' => $index,
                    'error' => $e->getMessage(),
                ]);
                // Skip this row but continue with others
                continue;
            }
        }

        // Validate JSON encoding before returning
        $payload = ['records' => $typedRows];
        $jsonTest = json_encode($payload);
        if ($jsonTest === false) {
            $error = json_last_error_msg();
            Log::error('Failed to encode payload as JSON', [
                'json_error' => $error,
                'json_error_code' => json_last_error(),
                'rows_count' => count($typedRows),
            ]);
            throw new \RuntimeException("Failed to encode payload as JSON: {$error}");
        }

        return $payload;
    }

    /**
     * Convert value to the specified type
     *
     * @param mixed $value
     * @param string $type
     * @return mixed
     */
    private function convertToType($value, string $type)
    {
        // Handle null values
        if ($value === null) {
            return null;
        }

        switch ($type) {
            case 'integer':
            case 'int':
                // Convert to integer, handle strings and floats
                if (is_string($value) && trim($value) === '') {
                    return null;
                }
                return (int) $value;

            case 'float':
            case 'double':
                // Convert to float, handle strings and integers
                if (is_string($value) && trim($value) === '') {
                    return null;
                }
                return (float) $value;

            case 'boolean':
            case 'bool':
                // Convert to boolean
                if (is_string($value)) {
                    $lower = strtolower(trim($value));
                    return in_array($lower, ['1', 'true', 'yes', 'on'], true);
                }
                return (bool) $value;

            case 'datetime':
            case 'date':
            case 'timestamp':
                // Keep as string (ISO 8601 format)
                return is_string($value) ? $value : (string) $value;

            case 'json':
                // If already an array/object, encode it; if string, keep as is
                if (is_array($value) || is_object($value)) {
                    return json_encode($value);
                }
                return is_string($value) ? $value : json_encode($value);

            case 'string':
            default:
                // Convert to string
                return (string) $value;
        }
    }

    /**
     * Send data to Databox with retry logic
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $payload
     * @param string $endpoint Full endpoint URL
     * @return array{success: bool, error?: string, status?: int}
     */
    private function sendWithRetry(array $config, array $payload, string $endpoint): array
    {
        $apiKey = $config['api_key'];
        $maxRetries = (int) ($config['retry_attempts'] ?? 3);
        $retryDelay = (int) ($config['retry_delay'] ?? 1000);

        if (!$apiKey) {
            return [
                'success' => false,
                'error' => 'Databox API key not configured',
            ];
        }

        $lastError = null;
        $lastStatus = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $this->makeRequest($apiKey, $endpoint, $payload, $config);

                if ($response['success']) {
                    return ['success' => true, 'status' => $response['status'] ?? 200];
                }

                $lastError = $response['error'] ?? 'Unknown error';
                $lastStatus = $response['status'] ?? null;

                // Don't retry on certain errors (e.g., authentication, bad request)
                if ($lastStatus === 401 || $lastStatus === 400) {
                    break;
                }

                // Wait before retrying (exponential backoff)
                if ($attempt < $maxRetries) {
                    $delay = $retryDelay * $attempt; // Exponential backoff
                    Log::warning('Databox request failed, retrying', [
                        'attempt' => $attempt,
                        'max_retries' => $maxRetries,
                        'delay_ms' => $delay,
                        'error' => $lastError,
                    ]);
                    usleep($delay * 1000); // Convert to microseconds
                }
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                Log::warning('Databox request exception', [
                    'attempt' => $attempt,
                    'error' => $lastError,
                ]);

                if ($attempt < $maxRetries) {
                    $delay = $retryDelay * $attempt;
                    usleep($delay * 1000);
                }
            }
        }

        return [
            'success' => false,
            'status' => $lastStatus,
            'error' => $lastError ?? 'Request failed after all retries',
        ];
    }

    /**
     * Make HTTP request to Databox API
     *
     * @param string $apiKey
     * @param string $endpoint Full endpoint URL
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $config
     * @param string $method HTTP method (GET, POST, etc.)
     * @return array{success: bool, status?: int, error?: string, id?: int|string, data?: array}
     */
    private function makeRequest(string $apiKey, string $endpoint, array $payload, array $config, string $method = 'POST'): array
    {
        // On Windows, prefer curl if available
        $useCurl = PHP_OS_FAMILY === 'Windows' && function_exists('curl_init');

        if ($useCurl) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                $jsonPayload = json_encode($payload);
                if ($jsonPayload === false) {
                    $jsonError = json_last_error_msg();
                    Log::error('Failed to encode payload as JSON for curl', [
                        'json_error' => $jsonError,
                        'json_error_code' => json_last_error(),
                    ]);
                    return [
                        'success' => false,
                        'status' => 0,
                        'error' => "Failed to encode payload as JSON: {$jsonError}",
                    ];
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            } elseif ($method === 'GET') {
                curl_setopt($ch, CURLOPT_HTTPGET, true);
                if (!empty($payload)) {
                    $endpoint .= '?' . http_build_query($payload);
                    curl_setopt($ch, CURLOPT_URL, $endpoint);
                }
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                "x-api-key: {$apiKey}",
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, (int) $config['timeout']);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects (301, 302, etc.)
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5); // Maximum number of redirects to follow

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            // Log response for debugging
            Log::info('Databox API request (curl)', [
                'method' => $method,
                'url' => $endpoint,
                'http_code' => $httpCode,
                'response_length' => strlen($result),
                'is_html' => str_starts_with(trim($result), '<'),
                'response_preview' => substr($result, 0, 500),
                'has_api_key' => !empty($apiKey),
            ]);

            if ($result === false) {
                return [
                    'success' => false,
                    'status' => $httpCode ?: 0,
                    'error' => $curlError ?: 'Curl request failed',
                ];
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                // Try to parse response for IDs or data
                $responseData = json_decode($result, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($responseData)) {
                    return [
                        'success' => true,
                        'status' => $httpCode,
                        'id' => $responseData['id'] ?? null,
                        'data' => $responseData,
                    ];
                }
                return ['success' => true, 'status' => $httpCode];
            }

            // Try to parse JSON error, otherwise return truncated HTML/error
            $errorData = json_decode($result, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($errorData)) {
                // Check for errors array (Databox API format)
                if (isset($errorData['errors']) && is_array($errorData['errors']) && !empty($errorData['errors'])) {
                    $firstError = $errorData['errors'][0];
                    $error = $firstError['message'] ?? $firstError['code'] ?? 'Unknown error';
                } else {
                    $error = $errorData['message'] ?? $errorData['error'] ?? 'Unknown error';
                }
            } else {
                // It's HTML or plain text - extract first 200 chars
                $error = 'HTTP ' . $httpCode . ': ' . substr(strip_tags($result), 0, 200);
            }
            return [
                'success' => false,
                'status' => $httpCode,
                'error' => $error,
            ];
        }

        // Fallback to Laravel HTTP client
        try {
            $httpClient = Http::timeout($config['timeout'])
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
                    'Content-Type' => 'application/json',
                    'x-api-key' => $apiKey,
                ]);

            if ($method === 'GET') {
                $response = $httpClient->get($endpoint, $payload);
            } else {
                $response = $httpClient->post($endpoint, $payload);
            }

            if ($response->successful()) {
                $responseData = $response->json();
                return [
                    'success' => true,
                    'status' => $response->status(),
                    'id' => $responseData['id'] ?? null,
                    'data' => $responseData,
                ];
            }

            // Try to parse JSON error, otherwise return truncated response
            $body = $response->body();
            $errorData = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($errorData)) {
                // Check for errors array (Databox API format)
                if (isset($errorData['errors']) && is_array($errorData['errors']) && !empty($errorData['errors'])) {
                    $firstError = $errorData['errors'][0];
                    $error = $firstError['message'] ?? $firstError['code'] ?? 'Unknown error';
                } else {
                    $error = $errorData['message'] ?? $errorData['error'] ?? 'Unknown error';
                }
            } else {
                // It's HTML or plain text - extract first 200 chars
                $error = 'HTTP ' . $response->status() . ': ' . substr(strip_tags($body), 0, 200);
            }
            return [
                'success' => false,
                'status' => $response->status(),
                'error' => $error,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * List available Databox accounts
     *
     * @return array{success: bool, accounts?: array, error?: string}
     */
    public function listAccounts(): array
    {
        $config = Config::get('databox');
        $apiKey = $config['api_key'];
        // Ensure we have the full endpoint URL
        $baseUrl = rtrim($config['api_url'], '/');
        $endpoint = "{$baseUrl}/accounts";

        if (!$apiKey) {
            return [
                'success' => false,
                'error' => 'Databox API key not configured',
            ];
        }

        Log::info('Attempting to list Databox accounts', ['endpoint' => $endpoint]);
        
        $response = $this->makeRequest($apiKey, $endpoint, [], $config, 'GET');
        
        if ($response['success'] && isset($response['data'])) {
            // Handle different response formats
            $accounts = $response['data']['accounts'] ?? $response['data'] ?? [];
            return [
                'success' => true,
                'accounts' => is_array($accounts) ? $accounts : [$accounts],
            ];
        }

        // If 404, the endpoint might not exist - that's okay, we can still use datasets directly
        if (isset($response['status']) && $response['status'] === 404) {
            Log::warning('Accounts endpoint not found - this may be normal for some API versions');
            return [
                'success' => false,
                'error' => 'Accounts endpoint not available. You may need to create datasets directly using dataset IDs.',
            ];
        }

        return $response;
    }

    /**
     * Create a data source in Databox
     *
     * @param string $title
     * @param int|null $accountId Optional - if not provided, uses account associated with API key
     * @param string $timezone
     * @return array{success: bool, id?: int, error?: string}
     */
    public function createDataSource(string $title, ?int $accountId = null, string $timezone = 'UTC'): array
    {
        $config = Config::get('databox');
        $apiKey = $config['api_key'];
        $baseUrl = rtrim($config['api_url'], '/');
        $endpoint = "{$baseUrl}/data-sources";

        if (!$apiKey) {
            return [
                'success' => false,
                'error' => 'Databox API key not configured',
            ];
        }

        $payload = [
            'title' => $title,
            'timezone' => $timezone,
        ];
        
        // Only include accountId if provided (optional per API docs)
        if ($accountId !== null) {
            $payload['accountId'] = $accountId;
        }

        $response = $this->makeRequest($apiKey, $endpoint, $payload, $config, 'POST');
        return $response;
    }

    /**
     * List datasets for a data source
     *
     * @param int $dataSourceId
     * @return array{success: bool, datasets?: array, error?: string}
     */
    public function listDatasets(int $dataSourceId): array
    {
        $config = Config::get('databox');
        $apiKey = $config['api_key'];
        $baseUrl = rtrim($config['api_url'], '/');
        $endpoint = "{$baseUrl}/data-sources/{$dataSourceId}/datasets";

        if (!$apiKey) {
            return [
                'success' => false,
                'error' => 'Databox API key not configured',
            ];
        }

        $response = $this->makeRequest($apiKey, $endpoint, [], $config, 'GET');
        
        if ($response['success'] && isset($response['data']['datasets'])) {
            return [
                'success' => true,
                'datasets' => $response['data']['datasets'],
            ];
        }

        return $response;
    }

    /**
     * Create a dataset in Databox
     *
     * @param int $dataSourceId
     * @param string $title
     * @param array<string> $primaryKeys
     * @return array{success: bool, id?: string, error?: string}
     */
    public function createDataset(int $dataSourceId, string $title, array $primaryKeys = []): array
    {
        $config = Config::get('databox');
        $apiKey = $config['api_key'];
        $baseUrl = rtrim($config['api_url'], '/');
        $endpoint = "{$baseUrl}/datasets";

        if (!$apiKey) {
            return [
                'success' => false,
                'error' => 'Databox API key not configured',
            ];
        }

        $payload = [
            'title' => $title,
            'dataSourceId' => $dataSourceId,
        ];

        if (!empty($primaryKeys)) {
            $payload['primaryKeys'] = $primaryKeys;
        }

        $response = $this->makeRequest($apiKey, $endpoint, $payload, $config, 'POST');
        return $response;
    }
}

