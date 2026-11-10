<?php

declare(strict_types=1);

namespace AudioTranscription\DTO;

use AudioTranscription\DiarizationStatus;
use AudioTranscription\ErrorCode;
use AudioTranscription\Sentiment;
use AudioTranscription\Support\Value;
use AudioTranscription\TranscriptStatus;
use InvalidArgumentException;
use JsonSerializable;

final readonly class Transcript implements JsonSerializable
{
    /**
     * @param  list<Segment>  $segments
     * @param  array<mixed>|null  $fiveW1h  Who, what, when, where, why and how, as returned by the Actor.
     */
    public function __construct(
        public string $url,
        public TranscriptStatus $status,
        public bool $translated,
        public float $transcribedSeconds,
        public int $minutesTranscribed,
        public bool $truncated,
        public array $segments,
        public ?string $language = null,
        public ?float $languageProbability = null,
        public ?float $durationSeconds = null,
        public ?string $text = null,
        public ?int $speakers = null,
        public ?DiarizationStatus $diarization = null,
        public ?string $srt = null,
        public ?string $vtt = null,
        public ?string $errorCode = null,
        public ?string $error = null,
        public ?Summary $summary = null,
        public ?Tags $tags = null,
        public ?array $fiveW1h = null,
        public ?SentimentResult $sentiment = null,
        public bool $analysisTruncated = false,
    ) {}

    /**
     * @param  array<mixed>  $data
     *
     * @throws InvalidArgumentException when a required field is missing or has the wrong type
     */
    public static function fromArray(array $data): self
    {
        $statusValue = Value::string($data, 'status');
        $status = TranscriptStatus::tryFrom($statusValue)
            ?? throw new InvalidArgumentException("Unknown status '{$statusValue}'");

        $diarization = Value::nullableString($data, 'diarization');
        $summary = Value::nullableArray($data, 'summary');
        $tags = Value::nullableArray($data, 'tags');
        $sentiment = Value::nullableArray($data, 'sentiment');

        $segments = [];
        foreach (Value::array($data, 'segments') as $segment) {
            if (! is_array($segment)) {
                throw new InvalidArgumentException("Field 'segments' must contain objects");
            }

            $segments[] = Segment::fromArray($segment);
        }

        return new self(
            url: Value::string($data, 'url'),
            status: $status,
            translated: Value::bool($data, 'translated'),
            transcribedSeconds: Value::float($data, 'transcribed_seconds'),
            minutesTranscribed: Value::int($data, 'minutes_transcribed'),
            truncated: Value::bool($data, 'truncated'),
            segments: $segments,
            language: Value::nullableString($data, 'language'),
            languageProbability: Value::nullableFloat($data, 'language_probability'),
            durationSeconds: Value::nullableFloat($data, 'duration_seconds'),
            text: Value::nullableString($data, 'text'),
            speakers: Value::nullableInt($data, 'speakers'),
            diarization: $diarization === null ? null : DiarizationStatus::tryFrom($diarization),
            srt: Value::nullableString($data, 'srt'),
            vtt: Value::nullableString($data, 'vtt'),
            errorCode: Value::nullableString($data, 'error_code'),
            error: Value::nullableString($data, 'error'),
            summary: $summary === null ? null : Summary::fromArray($summary),
            tags: $tags === null ? null : Tags::fromArray($tags),
            fiveW1h: Value::nullableArray($data, 'five_w1h'),
            sentiment: $sentiment === null ? null : SentimentResult::fromArray($sentiment),
            analysisTruncated: Value::bool($data, 'analysis_truncated'),
        );
    }

    /**
     * The Actor's dataset row shape (snake_case keys), so `Transcript::fromArray($t->toArray())` restores an equal
     * transcript. Use it, or `json_encode($transcript)`, to store transcripts in a database, cache or queue.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'status' => $this->status->value,
            'language' => $this->language,
            'language_probability' => $this->languageProbability,
            'translated' => $this->translated,
            'duration_seconds' => $this->durationSeconds,
            'transcribed_seconds' => $this->transcribedSeconds,
            'minutes_transcribed' => $this->minutesTranscribed,
            'truncated' => $this->truncated,
            'text' => $this->text,
            'segments' => array_map(static fn (Segment $s): array => $s->toArray(), $this->segments),
            'speakers' => $this->speakers,
            'diarization' => $this->diarization?->value,
            'srt' => $this->srt,
            'vtt' => $this->vtt,
            'error_code' => $this->errorCode,
            'error' => $this->error,
            'summary' => $this->summary?->toArray(),
            'tags' => $this->tags?->toArray(),
            'five_w1h' => $this->fiveW1h,
            'sentiment' => $this->sentiment?->toArray(),
            'analysis_truncated' => $this->analysisTruncated,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function isSuccessful(): bool
    {
        return $this->status === TranscriptStatus::Success;
    }

    public function isPartial(): bool
    {
        return $this->status === TranscriptStatus::Partial;
    }

    public function isFailed(): bool
    {
        return $this->status === TranscriptStatus::Failed;
    }

    /** The failure reason as enum, or null when the file did not fail or the code is not known to this library. */
    public function getErrorCode(): ?ErrorCode
    {
        return $this->errorCode === null ? null : ErrorCode::tryFrom($this->errorCode);
    }

    public function getSummaryText(): ?string
    {
        return $this->summary?->summary;
    }

    /** @return list<string> */
    public function getTags(): array
    {
        return $this->tags->tags ?? [];
    }

    public function getSentiment(): ?Sentiment
    {
        return $this->sentiment?->sentiment;
    }

    /** Number of segments with text. */
    public function getSegmentCount(): int
    {
        return count($this->segments);
    }
}
