<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Sync data from external sources to Databox
 *
 * This command runs the complete pipeline:
 * 1. Extract data from external sources
 * 2. Transform data to schema format
 * 3. Ingest data to Databox
 *
 * This is a convenience command that runs extract, transform, and ingest
 * in sequence for one or all data sources.
 */
class SyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'databox:sync 
                            {source? : The data source to sync (github, strava). If omitted, syncs all sources}
                            {--skip-extract : Skip the extraction step}
                            {--skip-transform : Skip the transformation step (only if data is already transformed)}
                            {--limit= : Maximum number of records to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run complete sync pipeline: extract → transform → ingest';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $sourceName = $this->argument('source');
        $skipExtract = $this->option('skip-extract');
        $skipTransform = $this->option('skip-transform');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $sourceArg = $sourceName ? " {$sourceName}" : '';

        // Step 1: Extract
        if (!$skipExtract) {
            $this->info('Step 1: Extracting data from sources...');
            $this->newLine();
            $extractResult = $this->call('databox:extract', array_filter([
                'source' => $sourceName,
            ]));

            if ($extractResult !== self::SUCCESS) {
                $this->error('Extraction failed. Aborting sync.');
                return self::FAILURE;
            }
            $this->newLine();
        } else {
            $this->info('Skipping extraction step...');
        }

        // Step 2: Transform
        if (!$skipTransform) {
            $this->info('Step 2: Transforming data...');
            $this->newLine();
            $transformResult = $this->call('databox:transform', array_filter([
                'source' => $sourceName,
                '--limit' => $limit,
            ]));

            if ($transformResult !== self::SUCCESS) {
                $this->error('Transformation failed. Aborting sync.');
                return self::FAILURE;
            }
            $this->newLine();
        } else {
            $this->info('Skipping transformation step...');
        }

        // Step 3: Ingest
        $this->info('Step 3: Ingesting data to Databox...');
        $this->newLine();
        $ingestResult = $this->call('databox:ingest', array_filter([
            'source' => $sourceName,
            '--limit' => $limit,
        ]));

        if ($ingestResult !== self::SUCCESS) {
            $this->error('Ingestion failed.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('✓ Sync completed successfully!');

        return self::SUCCESS;
    }
}

