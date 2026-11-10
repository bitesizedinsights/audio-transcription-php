<?php

declare(strict_types=1);

namespace AudioTranscription\DTO;

use AudioTranscription\Support\Value;

final readonly class Segment
{
    public function __construct(
        public int $id,
        public float $start,
        public float $end,
        public string $text,
        public ?string $speaker = null,
    ) {}

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Value::int($data, 'id'),
            start: Value::float($data, 'start'),
            end: Value::float($data, 'end'),
            text: Value::string($data, 'text'),
            speaker: Value::nullableString($data, 'speaker'),
        );
    }

    /** Length of the segment in seconds. */
    public function getDuration(): float
    {
        return $this->end - $this->start;
    }
}
