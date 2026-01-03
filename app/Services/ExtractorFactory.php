<?php

namespace App\Services;

use App\Models\DataSource;
use InvalidArgumentException;

/**
 * Factory for creating data extractors
 *
 * Resolves the appropriate extractor based on the data source name.
 */
class ExtractorFactory
{
    public function __construct(
        private GitHubExtractor $githubExtractor,
        private StravaExtractor $stravaExtractor
    ) {}

    /**
     * Get extractor for the given data source
     *
     * @param DataSource|string $source DataSource model or source name (e.g., 'github', 'strava')
     * @return DataExtractorInterface
     * @throws InvalidArgumentException
     */
    public function getExtractor(DataSource|string $source): DataExtractorInterface
    {
        $sourceName = $source instanceof DataSource ? $source->name : $source;
        $sourceName = strtolower($sourceName);

        return match ($sourceName) {
            'github' => $this->githubExtractor,
            'strava' => $this->stravaExtractor,
            default => throw new InvalidArgumentException("Unknown data source: {$sourceName}. Supported sources: github, strava"),
        };
    }

    /**
     * Get all available extractor names
     *
     * @return array<string>
     */
    public function getAvailableSources(): array
    {
        return ['github', 'strava'];
    }
}

