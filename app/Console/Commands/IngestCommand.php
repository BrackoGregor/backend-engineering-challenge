<?php

namespace App\Console\Commands;

use App\Models\DataSource;
use App\Schemas\GitHubDatasetSchema;
use App\Schemas\StravaDatasetSchema;
use App\Services\DataTransformerService;
use App\Services\DataboxIngestionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Ingest transformed data to Databox
 *
 * This command transforms data from the database and sends it
 * to the Databox Ingestion API.
 */
class IngestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'databox:ingest 
                            {source? : The data source to ingest (github, strava). If omitted, ingests all sources}
                            {--limit= : Maximum number of records to ingest}
                            {--unprocessed : Only ingest records not yet sent to Databox}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Transform and send data to Databox Ingestion API';

    public function __construct(
        private DataTransformerService $transformer,
        private DataboxIngestionService $ingestionService,
        private GitHubDatasetSchema $githubSchema,
        private StravaDatasetSchema $stravaSchema
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $sourceName = $this->argument('source');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $onlyUnprocessed = $this->option('unprocessed');

        if ($sourceName) {
            return $this->ingestSource($sourceName, $limit, $onlyUnprocessed);
        }

        // Ingest all sources
        $sources = ['github', 'strava'];
        $allSuccess = true;

        foreach ($sources as $source) {
            $this->info("Ingesting {$source} data...");
            $result = $this->ingestSource($source, $limit, $onlyUnprocessed);
            if ($result !== self::SUCCESS) {
                $allSuccess = false;
            }
            $this->newLine();
        }

        return $allSuccess ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Ingest data from a specific source
     *
     * @param string $sourceName
     * @param int|null $limit
     * @param bool $onlyUnprocessed
     * @return int
     */
    private function ingestSource(string $sourceName, ?int $limit, bool $onlyUnprocessed): int
    {
        try {
            $dataSource = DataSource::where('name', strtolower($sourceName))->first();

            if (!$dataSource) {
                $this->error("Data source '{$sourceName}' not found in database.");
                return self::FAILURE;
            }

            $this->info("Transforming {$sourceName} data...");

            // Transform data
            $transformed = match (strtolower($sourceName)) {
                'github' => $this->transformer->transformGitHubFromDatabase(
                    $dataSource->id,
                    $limit,
                    $onlyUnprocessed
                ),
                'strava' => $this->transformer->transformStravaFromDatabase(
                    $dataSource->id,
                    $limit,
                    $onlyUnprocessed
                ),
                default => throw new \InvalidArgumentException("Unknown source: {$sourceName}"),
            };

            $transformedCount = count($transformed);

            if ($transformedCount === 0) {
                $this->warn("No data to ingest for {$sourceName}");
                return self::SUCCESS;
            }

            $this->info("Sending {$transformedCount} records to Databox...");

            // Get schema
            $schema = match (strtolower($sourceName)) {
                'github' => $this->githubSchema,
                'strava' => $this->stravaSchema,
                default => throw new \InvalidArgumentException("Unknown source: {$sourceName}"),
            };

            // Send to Databox
            $result = $this->ingestionService->send($dataSource, $schema, $transformed);

            if ($result['success']) {
                $this->info("✓ Successfully sent {$result['rows_sent']} records to Databox");
                Log::info("Ingest command completed", [
                    'source' => $sourceName,
                    'rows_sent' => $result['rows_sent'],
                ]);
                return self::SUCCESS;
            } else {
                $this->error("✗ Failed to send data to Databox: {$result['message']}");
                Log::error("Ingest command failed", [
                    'source' => $sourceName,
                    'message' => $result['message'],
                ]);
                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("✗ Error ingesting {$sourceName}: {$e->getMessage()}");
            Log::error("Ingest command exception", [
                'source' => $sourceName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }
    }
}

