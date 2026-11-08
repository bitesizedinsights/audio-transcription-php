<?php

declare(strict_types=1);

namespace AudioTranscription\DTO;

use AudioTranscription\Support\Value;

final readonly class Summary
{
    public function __construct(
        public string $summary,
        public ?int $originalLength = null,
        public ?int $summaryLength = null,
        public ?float $compressionRatio = null,
    ) {}

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): self
    {
        $stats = Value::nullableArray($data, 'stats') ?? [];

        return new self(
            summary: Value::string($data, 'summary'),
            originalLength: Value::nullableInt($stats, 'original_length'),
            summaryLength: Value::nullableInt($stats, 'summary_length'),
            compressionRatio: Value::nullableFloat($stats, 'compression_ratio'),
        );
    }
}
