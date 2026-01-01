<?php

namespace App\Services;

use App\Models\DataSource;

/**
 * Interface for data extractors
 *
 * Defines the contract for all data extractors that fetch data
 * from external APIs and store it in the database.
 */
interface DataExtractorInterface
{
    /**
     * Extract data from the external API
     *
     * @param DataSource $dataSource The data source configuration
     * @param array<string, mixed> $options Additional options for extraction
     * @return array{success: bool, records_extracted: int, message: string}
     */
    public function extract(DataSource $dataSource, array $options = []): array;

    /**
     * Get the name of the extractor
     *
     * @return string
     */
    public function getName(): string;
}


