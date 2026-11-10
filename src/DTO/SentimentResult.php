<?php

declare(strict_types=1);

namespace AudioTranscription\DTO;

use AudioTranscription\Sentiment;
use AudioTranscription\Support\Value;
use JsonSerializable;

final readonly class SentimentResult implements JsonSerializable
{
    /** @param  Sentiment|null  $sentiment  Null when the service returned a label this library does not know. */
    public function __construct(
        public ?Sentiment $sentiment,
        public ?float $confidence = null,
    ) {}

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            sentiment: Sentiment::tryFrom(Value::string($data, 'sentiment')),
            confidence: Value::nullableFloat($data, 'confidence'),
        );
    }

    /**
     * The Actor's field names, so the result can be passed back to `fromArray()`. An unknown label (null sentiment)
     * is stored as an empty string and read back as null.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['sentiment' => $this->sentiment->value ?? '', 'confidence' => $this->confidence];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
