<?php

declare(strict_types=1);

namespace AudioTranscription;

use AudioTranscription\DTO\Chunk;
use AudioTranscription\DTO\Segment;
use InvalidArgumentException;

/**
 * Groups transcript segments into chunks. A segment is never split, so every chunk starts and ends on a real segment
 * boundary and its time range stays exact. A single segment that is longer than the limits becomes a chunk of its own.
 */
final class Chunker
{
    /**
     * @param  list<Segment>  $segments
     * @param  float  $maxSeconds  Longest chunk, from the start of its first to the end of its last segment.
     * @param  int  $maxCharacters  Longest chunk text in characters.
     * @param  int  $overlapSegments  Segments repeated at the start of the next chunk when a size limit closes a
     *                                chunk. Never carried over a speaker change or a pause.
     * @param  bool  $splitOnSpeaker  Start a new chunk when the speaker changes (only with speaker labels).
     * @param  float|null  $pauseSeconds  Start a new chunk after a silence of at least this many seconds.
     * @return list<Chunk>
     *
     * @throws InvalidArgumentException when a limit is not positive or the overlap is negative
     */
    public static function chunk(
        array $segments,
        float $maxSeconds = 60.0,
        int $maxCharacters = 1000,
        int $overlapSegments = 0,
        bool $splitOnSpeaker = true,
        ?float $pauseSeconds = null,
    ): array {
        $invalidPause = $pauseSeconds !== null && $pauseSeconds <= 0;

        if ($maxSeconds <= 0 || $maxCharacters < 1 || $overlapSegments < 0 || $invalidPause) {
            throw new InvalidArgumentException(
                'maxSeconds, maxCharacters and pauseSeconds must be positive and overlapSegments must not be negative',
            );
        }

        $chunks = [];
        $current = [];
        $fresh = 0; // segments in $current that are not carried over from the previous chunk

        $emit = static function () use (&$chunks, &$current): void {
            $chunks[] = self::build(count($chunks), $current);
        };

        foreach ($segments as $segment) {
            if (trim($segment->text) === '') {
                continue;
            }

            while ($current !== []) {
                $last = $current[array_key_last($current)];
                $hardBreak = ($splitOnSpeaker && $segment->speaker !== $last->speaker)
                    || ($pauseSeconds !== null && $segment->start - $last->end >= $pauseSeconds);

                if ($hardBreak) {
                    $emit();
                    $current = [];
                    $fresh = 0;

                    break;
                }

                if (! self::exceeds($current, $segment, $maxSeconds, $maxCharacters)) {
                    break;
                }

                if ($fresh === 0) {
                    $current = []; // only carried segments left and the next one still does not fit: drop the overlap

                    break;
                }

                $emit();
                $carry = min($overlapSegments, count($current) - 1);
                $current = $carry > 0 ? array_slice($current, -$carry) : [];
                $fresh = 0;
            }

            $current[] = $segment;
            $fresh++;
        }

        if ($fresh > 0) {
            $emit();
        }

        return $chunks;
    }

    /** @param  list<Segment>  $current  Not empty. */
    private static function exceeds(array $current, Segment $next, float $maxSeconds, int $maxCharacters): bool
    {
        return $next->end - $current[0]->start > $maxSeconds
            || mb_strlen(self::join([...$current, $next])) > $maxCharacters;
    }

    /** @param  list<Segment>  $segments  Not empty. */
    private static function build(int $index, array $segments): Chunk
    {
        $speakers = [];
        $ids = [];
        $start = $segments[0]->start;
        $end = $start;

        foreach ($segments as $segment) {
            $ids[] = $segment->id;
            $end = $segment->end;

            if ($segment->speaker !== null && ! in_array($segment->speaker, $speakers, true)) {
                $speakers[] = $segment->speaker;
            }
        }

        return new Chunk($index, $start, $end, self::join($segments), $ids, $speakers);
    }

    /** @param  list<Segment>  $segments */
    private static function join(array $segments): string
    {
        return implode(' ', array_map(static fn (Segment $s): string => trim($s->text), $segments));
    }
}
