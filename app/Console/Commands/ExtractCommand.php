<?php

namespace App\Console\Commands;

use App\Models\DataSource;
use App\Services\ExtractorFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Extract data from external sources
 *
 * This command extracts raw data from configured data sources
 * (GitHub, Strava) and stores it in the database.
 */
class ExtractCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'databox:extract 
                            {source? : The data source to extract from (github, strava). If omitted, extracts from all sources}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Extract raw data from external data sources (GitHub, Strava)';

    public function __construct(
        private ExtractorFactory $extractorFactory
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $sourceName = $this->argument('source');

        if ($sourceName) {
            return $this->extractFromSource($sourceName);
        }

        // Extract from all sources
        $sources = $this->extractorFactory->getAvailableSources();
        $allSuccess = true;

        foreach ($sources as $source) {
            $this->info("Extracting from {$source}...");
            $result = $this->extractFromSource($source);
            if ($result !== self::SUCCESS) {
                $allSuccess = false;
            }
            $this->newLine();
        }

        return $allSuccess ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Extract data from a specific source
     *
     * @param string $sourceName
     * @return int
     */
    private function extractFromSource(string $sourceName): int
    {
        try {
            $dataSource = DataSource::where('name', strtolower($sourceName))->first();

            if (!$dataSource) {
                $this->error("Data source '{$sourceName}' not found in database.");
                $this->info("Available sources: " . implode(', ', $this->extractorFactory->getAvailableSources()));
                return self::FAILURE;
            }

            if (!$dataSource->is_active) {
                $this->warn("Data source '{$sourceName}' is inactive. Skipping.");
                return self::SUCCESS;
            }

            $extractor = $this->extractorFactory->getExtractor($dataSource);

            $this->info("Extracting data from {$sourceName}...");

            $result = $extractor->extract($dataSource, [
                'since' => now()->subDays(1000)->toIso8601String(),
            ]);

            if ($result['success']) {
                $this->info("✓ Successfully extracted {$result['records_extracted']} records from {$sourceName}");
                Log::info("Extract command completed", [
                    'source' => $sourceName,
                    'records' => $result['records_extracted'],
                ]);
                return self::SUCCESS;
            } else {
                $this->error("✗ Failed to extract data from {$sourceName}: {$result['message']}");
                Log::error("Extract command failed", [
                    'source' => $sourceName,
                    'message' => $result['message'],
                ]);
                return self::FAILURE;
            }
        } catch (\InvalidArgumentException $e) {
            $this->error("✗ {$e->getMessage()}");
            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error("✗ Unexpected error: {$e->getMessage()}");
            Log::error("Extract command exception", [
                'source' => $sourceName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }
    }
}

