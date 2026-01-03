<?php

namespace App\Console\Commands;

use App\Models\DataSource;
use App\Models\GitHubData;
use App\Models\StravaData;
use App\Schemas\GitHubDatasetSchema;
use App\Schemas\StravaDatasetSchema;
use App\Services\DataTransformerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Transform data from database to schema format
 *
 * This command transforms raw data stored in the database
 * into the standardized schema format ready for Databox ingestion.
 */
class TransformCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'databox:transform 
                            {source? : The data source to transform (github, strava). If omitted, transforms all sources}
                            {--limit= : Maximum number of records to transform}
                            {--unprocessed : Only transform records not yet sent to Databox}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Transform raw data from database into schema format for Databox ingestion';

    public function __construct(
        private DataTransformerService $transformer
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
            return $this->transformSource($sourceName, $limit, $onlyUnprocessed);
        }

        // Transform all sources
        $sources = ['github', 'strava'];
        $allSuccess = true;

        foreach ($sources as $source) {
            $this->info("Transforming {$source} data...");
            $result = $this->transformSource($source, $limit, $onlyUnprocessed);
            if ($result !== self::SUCCESS) {
                $allSuccess = false;
            }
            $this->newLine();
        }

        return $allSuccess ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Transform data from a specific source
     *
     * @param string $sourceName
     * @param int|null $limit
     * @param bool $onlyUnprocessed
     * @return int
     */
    private function transformSource(string $sourceName, ?int $limit, bool $onlyUnprocessed): int
    {
        try {
            $dataSource = DataSource::where('name', strtolower($sourceName))->first();

            if (!$dataSource) {
                $this->error("Data source '{$sourceName}' not found in database.");
                return self::FAILURE;
            }

            $this->info("Transforming {$sourceName} data...");

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

            $count = count($transformed);

            if ($count > 0) {
                $this->info("✓ Successfully transformed {$count} records from {$sourceName}");
                Log::info("Transform command completed", [
                    'source' => $sourceName,
                    'records' => $count,
                ]);
                return self::SUCCESS;
            } else {
                $this->warn("No data to transform for {$sourceName}");
                return self::SUCCESS;
            }
        } catch (\Exception $e) {
            $this->error("✗ Error transforming {$sourceName}: {$e->getMessage()}");
            Log::error("Transform command failed", [
                'source' => $sourceName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }
    }
}

