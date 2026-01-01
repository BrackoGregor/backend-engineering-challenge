<?php

namespace App\Schemas;

use Carbon\Carbon;

/**
 * GitHub Dataset Schema
 *
 * Defines the structure for GitHub data sent to Databox.
 * This schema transforms GitHub API responses into a standardized format.
 *
 * Schema Version: 1.0
 * Last Updated: 2024-01-15
 */
class GitHubDatasetSchema implements SchemaInterface
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
        return 'github_events';
    }

    /**
     * Get the schema fields definition
     *
     * @return array<string, array{type: string, description: string}>
     */
    public function getFields(): array
    {
        return [
            'repository_name' => [
                'type' => 'string',
                'description' => 'Full name of the repository (owner/repo)',
            ],
            'repository_id' => [
                'type' => 'integer',
                'description' => 'GitHub repository ID',
            ],
            'event_type' => [
                'type' => 'string',
                'description' => 'Type of event (push, pull_request, issue, etc.)',
            ],
            'actor' => [
                'type' => 'string',
                'description' => 'GitHub username of the actor',
            ],
            'actor_id' => [
                'type' => 'integer',
                'description' => 'GitHub user ID of the actor',
            ],
            'timestamp' => [
                'type' => 'datetime',
                'description' => 'ISO 8601 timestamp of the event',
            ],
            'commit_count' => [
                'type' => 'integer',
                'description' => 'Number of commits (for push events)',
            ],
            'branch' => [
                'type' => 'string',
                'description' => 'Branch name (for push events)',
            ],
            'is_public' => [
                'type' => 'boolean',
                'description' => 'Whether the repository is public',
            ],
            'metadata' => [
                'type' => 'json',
                'description' => 'Additional event-specific metadata',
            ],
        ];
    }

    /**
     * Transform raw GitHub API data to schema format
     *
     * Supports multiple GitHub API endpoints:
     * - Repository events
     * - Push events
     * - Pull request events
     * - Issue events
     *
     * @param array<string, mixed> $rawData
     * @return array<string, mixed>
     */
    public function transform(array $rawData): array
    {
        // Extract common fields
        $repository = $rawData['repository'] ?? $rawData['repo'] ?? [];
        $actor = $rawData['actor'] ?? $rawData['user'] ?? $rawData['sender'] ?? [];

        // Determine event type
        $eventType = $this->determineEventType($rawData);

        // Extract timestamp
        $timestamp = $this->extractTimestamp($rawData);

        // Build base schema
        $transformed = [
            'repository_name' => $repository['full_name'] ?? $repository['name'] ?? 'unknown',
            'repository_id' => $repository['id'] ?? null,
            'event_type' => $eventType,
            'actor' => $actor['login'] ?? $actor['name'] ?? 'unknown',
            'actor_id' => $actor['id'] ?? null,
            'timestamp' => $timestamp,
            'commit_count' => $this->extractCommitCount($rawData),
            'branch' => $this->extractBranch($rawData),
            'is_public' => $repository['private'] === false,
            'metadata' => $this->extractMetadata($rawData, $eventType),
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
            'repository.full_name' => 'repository_name',
            'repository.id' => 'repository_id',
            'type' => 'event_type',
            'actor.login' => 'actor',
            'actor.id' => 'actor_id',
            'created_at' => 'timestamp',
            'payload.commits' => 'commit_count (array length)',
            'payload.ref' => 'branch',
            'repository.private' => 'is_public (inverted)',
        ];
    }

    /**
     * Determine the event type from raw data
     */
    private function determineEventType(array $rawData): string
    {
        // Check explicit type field
        if (isset($rawData['type'])) {
            return $rawData['type'];
        }

        // Check payload type
        if (isset($rawData['payload']['action'])) {
            return $rawData['payload']['action'];
        }

        // Default based on available data
        if (isset($rawData['commits'])) {
            return 'push';
        }

        if (isset($rawData['pull_request'])) {
            return 'pull_request';
        }

        if (isset($rawData['issue'])) {
            return 'issue';
        }

        return 'unknown';
    }

    /**
     * Extract timestamp from raw data
     */
    private function extractTimestamp(array $rawData): string
    {
        $timestampFields = ['created_at', 'updated_at', 'pushed_at', 'timestamp'];

        foreach ($timestampFields as $field) {
            if (isset($rawData[$field])) {
                return Carbon::parse($rawData[$field])->toIso8601String();
            }
        }

        return Carbon::now()->toIso8601String();
    }

    /**
     * Extract commit count from raw data
     */
    private function extractCommitCount(array $rawData): int
    {
        if (isset($rawData['payload']['commits']) && is_array($rawData['payload']['commits'])) {
            return count($rawData['payload']['commits']);
        }

        if (isset($rawData['commits']) && is_array($rawData['commits'])) {
            return count($rawData['commits']);
        }

        return 0;
    }

    /**
     * Extract branch name from raw data
     */
    private function extractBranch(array $rawData): ?string
    {
        $ref = $rawData['payload']['ref'] ?? $rawData['ref'] ?? null;

        if ($ref && str_starts_with($ref, 'refs/heads/')) {
            return str_replace('refs/heads/', '', $ref);
        }

        return $ref;
    }

    /**
     * Extract event-specific metadata
     */
    private function extractMetadata(array $rawData, string $eventType): array
    {
        $metadata = [];

        // Extract payload data (excluding already mapped fields)
        if (isset($rawData['payload'])) {
            $excludedFields = ['ref', 'commits'];
            foreach ($rawData['payload'] as $key => $value) {
                if (!in_array($key, $excludedFields)) {
                    $metadata[$key] = $value;
                }
            }
        }

        // Add event-specific data
        switch ($eventType) {
            case 'pull_request':
                $metadata['pr_number'] = $rawData['payload']['number'] ?? null;
                $metadata['pr_state'] = $rawData['payload']['pull_request']['state'] ?? null;
                break;

            case 'issue':
                $metadata['issue_number'] = $rawData['payload']['issue']['number'] ?? null;
                $metadata['issue_state'] = $rawData['payload']['issue']['state'] ?? null;
                break;
        }

        return $metadata;
    }
}


