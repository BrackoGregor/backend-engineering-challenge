<?php

namespace App\Schemas;

use Carbon\Carbon;

/**
 * Strava Dataset Schema
 *
 * Defines the structure for Strava activity data sent to Databox.
 * This schema transforms Strava API responses into a standardized format.
 *
 * Schema Version: 1.0
 * Last Updated: 2024-01-15
 */
class StravaDatasetSchema implements SchemaInterface
{
    /**
     * Get the schema version
     */
    public function getVersion(): string
    {
        return '1.0';
    }

    /**
     * Get the dataset name
     */
    public function getDatasetName(): string
    {
        return 'strava_activities';
    }

    /**
     * Get the schema fields definition
     *
     * @return array<string, array{type: string, description: string}>
     */
    public function getFields(): array
    {
        return [
            'activity_id' => [
                'type' => 'integer',
                'description' => 'Strava activity ID',
            ],
            'activity_type' => [
                'type' => 'string',
                'description' => 'Type of activity (Run, Ride, Swim, etc.)',
            ],
            'name' => [
                'type' => 'string',
                'description' => 'Activity name',
            ],
            'distance' => [
                'type' => 'float',
                'description' => 'Distance in meters',
            ],
            'distance_km' => [
                'type' => 'float',
                'description' => 'Distance in kilometers',
            ],
            'moving_time' => [
                'type' => 'integer',
                'description' => 'Moving time in seconds',
            ],
            'elapsed_time' => [
                'type' => 'integer',
                'description' => 'Total elapsed time in seconds',
            ],
            'total_elevation_gain' => [
                'type' => 'float',
                'description' => 'Total elevation gain in meters',
            ],
            'elevation_high' => [
                'type' => 'float',
                'description' => 'Maximum elevation in meters',
            ],
            'elevation_low' => [
                'type' => 'float',
                'description' => 'Minimum elevation in meters',
            ],
            'start_date' => [
                'type' => 'datetime',
                'description' => 'Activity start date and time (ISO 8601)',
            ],
            'start_date_local' => [
                'type' => 'datetime',
                'description' => 'Activity start date and time in local timezone (ISO 8601)',
            ],
            'timezone' => [
                'type' => 'string',
                'description' => 'Timezone of the activity',
            ],
            'athlete_id' => [
                'type' => 'integer',
                'description' => 'Strava athlete ID',
            ],
            'average_speed' => [
                'type' => 'float',
                'description' => 'Average speed in meters per second',
            ],
            'average_speed_kmh' => [
                'type' => 'float',
                'description' => 'Average speed in kilometers per hour',
            ],
            'max_speed' => [
                'type' => 'float',
                'description' => 'Maximum speed in meters per second',
            ],
            'average_cadence' => [
                'type' => 'float',
                'description' => 'Average cadence (for cycling/running)',
            ],
            'average_heartrate' => [
                'type' => 'float',
                'description' => 'Average heart rate (if available)',
            ],
            'max_heartrate' => [
                'type' => 'float',
                'description' => 'Maximum heart rate (if available)',
            ],
            'calories' => [
                'type' => 'integer',
                'description' => 'Estimated calories burned',
            ],
            'suffer_score' => [
                'type' => 'integer',
                'description' => 'Suffer score (if available)',
            ],
            'is_private' => [
                'type' => 'boolean',
                'description' => 'Whether the activity is private',
            ],
            'gear_id' => [
                'type' => 'string',
                'description' => 'Gear ID used for the activity',
            ],
            'metadata' => [
                'type' => 'json',
                'description' => 'Additional activity metadata',
            ],
        ];
    }

    /**
     * Transform raw Strava API data to schema format
     *
     * @param array<string, mixed> $rawData
     * @return array<string, mixed>
     */
    public function transform(array $rawData): array
    {
        $distance = $rawData['distance'] ?? 0;
        $distanceKm = $distance / 1000;

        $averageSpeed = $rawData['average_speed'] ?? 0;
        $averageSpeedKmh = $averageSpeed * 3.6; // Convert m/s to km/h

        $transformed = [
            'activity_id' => $rawData['id'] ?? null,
            'activity_type' => $rawData['type'] ?? 'Unknown',
            'name' => $rawData['name'] ?? '',
            'distance' => $distance,
            'distance_km' => round($distanceKm, 2),
            'moving_time' => $rawData['moving_time'] ?? 0,
            'elapsed_time' => $rawData['elapsed_time'] ?? 0,
            'total_elevation_gain' => $rawData['total_elevation_gain'] ?? 0,
            'elevation_high' => $rawData['elevation_high'] ?? null,
            'elevation_low' => $rawData['elevation_low'] ?? null,
            'start_date' => $this->parseDate($rawData['start_date'] ?? null),
            'start_date_local' => $this->parseDate($rawData['start_date_local'] ?? null),
            'timezone' => $rawData['timezone'] ?? null,
            'athlete_id' => $rawData['athlete']['id'] ?? $rawData['athlete_id'] ?? null,
            'average_speed' => $averageSpeed,
            'average_speed_kmh' => round($averageSpeedKmh, 2),
            'max_speed' => $rawData['max_speed'] ?? null,
            'average_cadence' => $rawData['average_cadence'] ?? null,
            'average_heartrate' => $rawData['average_heartrate'] ?? null,
            'max_heartrate' => $rawData['max_heartrate'] ?? null,
            'calories' => $rawData['calories'] ?? null,
            'suffer_score' => $rawData['suffer_score'] ?? null,
            'is_private' => $rawData['private'] ?? false,
            'gear_id' => $rawData['gear_id'] ?? null,
            'metadata' => $this->extractMetadata($rawData),
        ];

        return $transformed;
    }

    /**
     * Get the source data mapping documentation
     *
     * @return array<string, string>
     */
    public function getSourceMapping(): array
    {
        return [
            'id' => 'activity_id',
            'type' => 'activity_type',
            'name' => 'name',
            'distance' => 'distance (meters)',
            'distance / 1000' => 'distance_km',
            'moving_time' => 'moving_time (seconds)',
            'elapsed_time' => 'elapsed_time (seconds)',
            'total_elevation_gain' => 'total_elevation_gain (meters)',
            'elevation_high' => 'elevation_high (meters)',
            'elevation_low' => 'elevation_low (meters)',
            'start_date' => 'start_date (ISO 8601)',
            'start_date_local' => 'start_date_local (ISO 8601)',
            'timezone' => 'timezone',
            'athlete.id' => 'athlete_id',
            'average_speed' => 'average_speed (m/s)',
            'average_speed * 3.6' => 'average_speed_kmh',
            'max_speed' => 'max_speed (m/s)',
            'average_cadence' => 'average_cadence',
            'average_heartrate' => 'average_heartrate',
            'max_heartrate' => 'max_heartrate',
            'calories' => 'calories',
            'suffer_score' => 'suffer_score',
            'private' => 'is_private',
            'gear_id' => 'gear_id',
        ];
    }

    /**
     * Parse and format date string
     */
    private function parseDate(?string $dateString): ?string
    {
        if (!$dateString) {
            return null;
        }

        try {
            return Carbon::parse($dateString)->toIso8601String();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extract additional metadata not in main schema
     */
    private function extractMetadata(array $rawData): array
    {
        $metadata = [];

        // Include fields that might be useful but not in main schema
        $optionalFields = [
            'description',
            'workout_type',
            'trainer',
            'commute',
            'manual',
            'device_name',
            'embed_token',
            'splits_metric',
            'splits_standard',
            'laps',
            'best_efforts',
            'segment_efforts',
        ];

        foreach ($optionalFields as $field) {
            if (isset($rawData[$field])) {
                $metadata[$field] = $rawData[$field];
            }
        }

        return $metadata;
    }
}


