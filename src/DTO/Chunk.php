<?php

declare(strict_types=1);

namespace AudioTranscription\DTO;

use JsonSerializable;

/**
 * A run of consecutive transcript segments sized for retrieval (RAG): the unit that is embedded, with the time range
 * and speakers needed to cite the exact moment. The segments themselves stay on the `Transcript` for playback.
 */
final readonly class Chunk implements JsonSerializable
{
    /**
     * @param  int  $index  Position of the chunk in the transcript, starting at 0.
     * @param  string  $text  The segment texts joined with a space, without speaker labels.
     * @param  list<int>  $segmentIds  `Segment::$id` of every segment in the chunk, in order.
     * @param  list<string>  $speakers  Distinct speaker labels in order of appearance; empty without diarization.
     */
    public function __construct(
        public int $index,
        public float $start,
        public float $end,
        public string $text,
        public array $segmentIds,
        public array $speakers = [],
    ) {}

    /** Length of the chunk in seconds. */
    public function getDuration(): float
    {
        return $this->end - $this->start;
    }

    /** The speaker label when the whole chunk has one speaker, otherwise null. */
    public function getSpeaker(): ?string
    {
        return count($this->speakers) === 1 ? $this->speakers[0] : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'start' => $this->start,
            'end' => $this->end,
            'text' => $this->text,
            'segment_ids' => $this->segmentIds,
            'speakers' => $this->speakers,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
