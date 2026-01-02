<?php

namespace App\Services;

use App\Models\GitHubData;
use App\Models\StravaData;
use App\Schemas\GitHubDatasetSchema;
use App\Schemas\SchemaInterface;
use App\Schemas\StravaDatasetSchema;
use Illuminate\Support\Facades\Log;

/**
 * Data Transformer Service
 *
 * Transforms raw API data stored in the database into
 * standardized schema format for Databox ingestion.
 */
class DataTransformerService
{
    public function __construct(
        private GitHubDatasetSchema $githubSchema,
        private StravaDatasetSchema $stravaSchema
    ) {}

    /**
     * Transform GitHub data to schema format
     *
     * @param array<int, GitHubData>|GitHubData $data
     * @return array<int, array<string, mixed>>
     */
    public function transformGitHub($data): array
    {
        return $this->transform($data, $this->githubSchema, 'raw_data');
    }

    /**
     * Transform Strava data to schema format
     *
     * @param array<int, StravaData>|StravaData $data
     * @return array<int, array<string, mixed>>
     */
    public function transformStrava($data): array
    {
        return $this->transform($data, $this->stravaSchema, 'raw_data');
    }

    /**
     * Transform data using the provided schema
     *
     * @param array<int, mixed>|mixed $data Single model or collection
     * @param SchemaInterface $schema
     * @param string $rawDataField Field name containing raw data (default: 'raw_data')
     * @return array<int, array<string, mixed>>
     */
    public function transform($data, SchemaInterface $schema, string $rawDataField = 'raw_data'): array
    {
        $transformed = [];

        // Normalize to iterable
        // If it's a Collection or other iterable, use it directly
        // If it's a single item, wrap it in an array
        if (is_iterable($data) && !is_array($data)) {
            // It's a Collection or other iterable - iterate directly
            $items = $data;
        } elseif (!is_array($data)) {
            // Single item - wrap in array
            $items = [$data];
        } else {
            // Already an array
            $items = $data;
        }

        foreach ($items as $index => $item) {
            try {
                // Get raw data - directly access the property (Laravel cast will apply)
                if (is_object($item)) {
                    $rawData = $item->$rawDataField;
                } elseif (is_array($item)) {
                    $rawData = $item[$rawDataField] ?? null;
                } else {
                    $rawData = $item;
                }

                // Ensure we have data
                if ($rawData === null) {
                    continue;
                }

                // Convert to array if needed
                if (!is_array($rawData)) {
                    if (is_string($rawData)) {
                        $rawData = json_decode($rawData, true);
                        if (!is_array($rawData)) {
                            continue;
                        }
                    } elseif (is_object($rawData)) {
                        $rawData = json_decode(json_encode($rawData), true);
                    } else {
                        continue;
                    }
                }

                // For GitHub data, add repository_name from model if not in raw data
                if (is_object($item) && $item instanceof \App\Models\GitHubData && !isset($rawData['repository'])) {
                    $rawData['repository'] = [
                        'full_name' => $item->repository_name,
                        'name' => $item->repository_name,
                    ];
                }

                // For GitHub data, add repository_name from model if not in raw data
                if (is_object($item) && $item instanceof \App\Models\GitHubData && !isset($rawData['repository'])) {
                    $rawData['repository'] = [
                        'full_name' => $item->repository_name,
                        'name' => $item->repository_name,
                    ];
                }

                // Transform using schema
                try {
                    $transformedRow = $schema->transform($rawData);
                } catch (\Exception $e) {
                    Log::error('Schema transform failed', [
                        'schema' => $schema->getDatasetName(),
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }

                // Validate transformed data
                if (!is_array($transformedRow) || count($transformedRow) === 0) {
                    continue;
                }

                $transformed[] = $transformedRow;
            } catch (\Exception $e) {
                Log::error('Error transforming data row', [
                    'schema' => $schema->getDatasetName(),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                // Continue with next row instead of failing completely
                continue;
            }
        }

        return $transformed;
    }

    /**
     * Transform GitHub data from database records
     *
     * @param int|null $sourceId Filter by source ID
     * @param int|null $limit Maximum number of records to transform
     * @param bool $onlyUnprocessed Only transform records not yet sent to Databox
     * @return array<int, array<string, mixed>>
     */
    public function transformGitHubFromDatabase(
        ?int $sourceId = null,
        ?int $limit = null,
        bool $onlyUnprocessed = false
    ): array {
        $query = GitHubData::query();

        if ($sourceId) {
            $query->where('source_id', $sourceId);
        }

        // TODO: Add processed flag when we implement tracking
        // if ($onlyUnprocessed) {
        //     $query->whereNull('processed_at');
        // }

        if ($limit) {
            $query->limit($limit);
        }

        $data = $query->get();

        return $this->transformGitHub($data);
    }

    /**
     * Transform Strava data from database records
     *
     * @param int|null $sourceId Filter by source ID
     * @param int|null $limit Maximum number of records to transform
     * @param bool $onlyUnprocessed Only transform records not yet sent to Databox
     * @return array<int, array<string, mixed>>
     */
    public function transformStravaFromDatabase(
        ?int $sourceId = null,
        ?int $limit = null,
        bool $onlyUnprocessed = false
    ): array {
        $query = StravaData::query();

        if ($sourceId) {
            $query->where('source_id', $sourceId);
        }

        // TODO: Add processed flag when we implement tracking
        // if ($onlyUnprocessed) {
        //     $query->whereNull('processed_at');
        // }

        if ($limit) {
            $query->limit($limit);
        }

        $data = $query->get();

        return $this->transformStrava($data);
    }
}

