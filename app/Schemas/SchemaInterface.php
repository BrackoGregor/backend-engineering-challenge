<?php

namespace App\Schemas;

/**
 * Interface for dataset schemas
 *
 * Defines the contract for all dataset schemas that transform
 * raw API data into a standardized format for Databox ingestion.
 */
interface SchemaInterface
{
    /**
     * Get the schema version
     *
     * @return string
     */
    public function getVersion(): string;

    /**
     * Get the dataset name
     *
     * @return string
     */
    public function getDatasetName(): string;

    /**
     * Get the schema fields definition
     *
     * Returns an array of field definitions with name, type, and description
     *
     * @return array<string, array{type: string, description: string}>
     */
    public function getFields(): array;

    /**
     * Transform raw API data to schema format
     *
     * @param array<string, mixed> $rawData
     * @return array<string, mixed>
     */
    public function transform(array $rawData): array;

    /**
     * Get the source data mapping documentation
     *
     * Returns an array describing how source fields map to schema fields
     *
     * @return array<string, string>
     */
    public function getSourceMapping(): array;
}


