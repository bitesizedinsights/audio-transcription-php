<?php

declare(strict_types=1);

namespace AudioTranscription\DTO;

use AudioTranscription\Chunker;
use AudioTranscription\DiarizationStatus;
use AudioTranscription\ErrorCode;
use AudioTranscription\Sentiment;
use AudioTranscription\Support\Value;
use AudioTranscription\TranscriptStatus;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use JsonSerializable;
use RuntimeException;

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
        public int $minutesCached = 0,
        public ?string $message = null,
        public ?string $sourceFeed = null,
        public ?string $episodeTitle = null,
        public ?string $episodePublished = null,
        public ?string $episodeGuid = null,
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
            minutesCached: Value::nullableInt($data, 'minutes_cached') ?? 0,
            message: Value::nullableString($data, 'message'),
            sourceFeed: Value::nullableString($data, 'source_feed'),
            episodeTitle: Value::nullableString($data, 'episode_title'),
            episodePublished: Value::nullableString($data, 'episode_published'),
            episodeGuid: Value::nullableString($data, 'episode_guid'),
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
            'minutes_cached' => $this->minutesCached,
            'message' => $this->message,
            'source_feed' => $this->sourceFeed,
            'episode_title' => $this->episodeTitle,
            'episode_published' => $this->episodePublished,
            'episode_guid' => $this->episodeGuid,
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

    /** Not started because the run's time limit was too close; run the same input again. */
    public function isSkipped(): bool
    {
        return $this->status === TranscriptStatus::Skipped;
    }

    /** True for `Partial` and `Skipped`: running the same input again continues where this one stopped. */
    public function needsRerun(): bool
    {
        return $this->isPartial() || $this->isSkipped();
    }

    /** The episode's publication time, null for plain links or when the feed gave none. */
    public function getEpisodePublishedAt(): ?DateTimeImmutable
    {
        if ($this->episodePublished === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($this->episodePublished);
        } catch (Exception) {
            return null;
        }
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

    /**
     * The media URL with a media fragment (`#t=SECONDS`) that starts playback at the segment, for HTML5 audio and
     * video players. Any fragment already on the URL is replaced.
     */
    public function getSegmentUrl(Segment $segment): string
    {
        return $this->urlAt($segment->start);
    }

    /** Like `getSegmentUrl()`, for the start of a chunk, to cite where a retrieved chunk begins. */
    public function getChunkUrl(Chunk $chunk): string
    {
        return $this->urlAt($chunk->start);
    }

    private function urlAt(float $seconds): string
    {
        return explode('#', $this->url, 2)[0].'#t='.(int) floor($seconds);
    }

    /**
     * Group the segments into chunks for retrieval (RAG), each with its time range, segment IDs and speakers. A
     * segment is never split. A chunk is closed when the next segment would push it over `$maxSeconds` or
     * `$maxCharacters`, when the speaker changes (`$splitOnSpeaker`, needs `diarize`) or after a pause of at least
     * `$pauseSeconds`.
     *
     * - Fixed time window: `chunks(maxSeconds: 60, splitOnSpeaker: false)` with a large `maxCharacters`.
     * - Size capped: `chunks(maxSeconds: 600, maxCharacters: 800)`.
     * - Speaker and pause aware: `chunks(splitOnSpeaker: true, pauseSeconds: 2.0)`.
     *
     * @param  float  $maxSeconds  Longest chunk in seconds, first segment start to last segment end.
     * @param  int  $maxCharacters  Longest chunk text in characters.
     * @param  int  $overlapSegments  Segments repeated at the start of the next chunk after a size break.
     * @param  float|null  $pauseSeconds  Start a new chunk after a silence of at least this many seconds.
     * @return list<Chunk>
     *
     * @throws InvalidArgumentException when a limit is not positive or the overlap is negative
     */
    public function chunks(
        float $maxSeconds = 60.0,
        int $maxCharacters = 1000,
        int $overlapSegments = 0,
        bool $splitOnSpeaker = true,
        ?float $pauseSeconds = null,
    ): array {
        return Chunker::chunk(
            $this->segments,
            $maxSeconds,
            $maxCharacters,
            $overlapSegments,
            $splitOnSpeaker,
            $pauseSeconds,
        );
    }

    /** One line per segment, `[m:ss] text` (`[m:ss] SPEAKER_00: text` with speaker labels). */
    public function getTimestampedText(): string
    {
        return implode("\n", array_map(
            static fn (Segment $s): string => sprintf(
                '[%s] %s%s',
                $s->getTimestamp(),
                $s->speaker === null ? '' : $s->speaker.': ',
                $s->text,
            ),
            $this->segments,
        ));
    }

    /**
     * Write the SRT subtitles to a file.
     *
     * @throws RuntimeException when SRT was not requested (`SubtitleFormat::Srt`) or the file cannot be written
     */
    public function saveSrt(string $path): void
    {
        $this->save($this->srt, $path, 'SRT');
    }

    /**
     * Write the WebVTT subtitles to a file.
     *
     * @throws RuntimeException when WebVTT was not requested (`SubtitleFormat::Vtt`) or the file cannot be written
     */
    public function saveVtt(string $path): void
    {
        $this->save($this->vtt, $path, 'WebVTT');
    }

    private function save(?string $content, string $path, string $label): void
    {
        if ($content === null) {
            throw new RuntimeException("No {$label} subtitles in this transcript; request them with subtitleFormats");
        }

        if (! is_dir(dirname($path)) || @file_put_contents($path, $content) === false) {
            throw new RuntimeException("Cannot write {$label} subtitles to {$path}");
        }
    }

    /** Number of segments with text. */
    public function getSegmentCount(): int
    {
        return count($this->segments);
    }
}
