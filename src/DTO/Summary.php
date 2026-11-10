<?php

declare(strict_types=1);

namespace AudioTranscription\DTO;

use AudioTranscription\Support\Value;
use JsonSerializable;

final readonly class Summary implements JsonSerializable
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

    /**
     * The Actor's field names, so the result can be passed back to `fromArray()`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'stats' => [
                'original_length' => $this->originalLength,
                'summary_length' => $this->summaryLength,
                'compression_ratio' => $this->compressionRatio,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
