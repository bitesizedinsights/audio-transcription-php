<?php

declare(strict_types=1);

namespace AudioTranscription\DTO;

use AudioTranscription\Support\Value;
use JsonSerializable;

final readonly class Segment implements JsonSerializable
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

    /**
     * The Actor's field names, so the result can be passed back to `fromArray()`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'start' => $this->start,
            'end' => $this->end,
            'text' => $this->text,
            'speaker' => $this->speaker,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** Start time as `m:ss`, or `h:mm:ss` from one hour on, for example `1:05` or `1:02:03`. */
    public function getTimestamp(): string
    {
        $total = max(0, (int) floor($this->start));
        $hours = intdiv($total, 3600);
        $minutes = intdiv($total % 3600, 60);
        $seconds = $total % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $seconds)
            : sprintf('%d:%02d', $minutes, $seconds);
    }

    /** Length of the segment in seconds. */
    public function getDuration(): float
    {
        return $this->end - $this->start;
    }
}
