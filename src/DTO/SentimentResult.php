<?php

declare(strict_types=1);

namespace AudioTranscription\DTO;

use AudioTranscription\Sentiment;
use AudioTranscription\Support\Value;

final readonly class SentimentResult
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
}
