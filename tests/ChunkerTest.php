<?php

declare(strict_types=1);

use AudioTranscription\Chunker;
use AudioTranscription\DTO\Chunk;
use AudioTranscription\DTO\Segment;
use AudioTranscription\DTO\Transcript;
use AudioTranscription\TranscriptStatus;

/**
 * @param  list<array{0: float, 1: float, 2: string, 3?: string|null}>  $rows  start, end, text, speaker
 * @return list<Segment>
 */
function segs(array $rows): array
{
    $segments = [];

    foreach ($rows as $id => $row) {
        $segments[] = new Segment($id, $row[0], $row[1], $row[2], $row[3] ?? null);
    }

    return $segments;
}

function transcriptWith(array $segments, string $url = 'https://a.test/ep.mp3'): Transcript
{
    return new Transcript($url, TranscriptStatus::Success, false, 100.0, 2, false, $segments);
}

test('no segments give no chunks, blank segments are skipped', function () {
    expect(Chunker::chunk([]))->toBe([])
        ->and(Chunker::chunk(segs([[0.0, 1.0, '  '], [1.0, 2.0, '']])))->toBe([]);
});

test('segments within the limits become one chunk', function () {
    $chunks = Chunker::chunk(segs([[0.0, 5.0, 'Hello there.'], [5.0, 10.0, 'How are you?']]));

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0])->toBeInstanceOf(Chunk::class)
        ->and($chunks[0]->index)->toBe(0)
        ->and($chunks[0]->start)->toBe(0.0)
        ->and($chunks[0]->end)->toBe(10.0)
        ->and($chunks[0]->text)->toBe('Hello there. How are you?')
        ->and($chunks[0]->segmentIds)->toBe([0, 1])
        ->and($chunks[0]->speakers)->toBe([])
        ->and($chunks[0]->getSpeaker())->toBeNull()
        ->and($chunks[0]->getDuration())->toBe(10.0);
});

test('time window closes a chunk before the segment that would exceed it', function () {
    $segments = segs([[0.0, 20.0, 'a'], [20.0, 40.0, 'b'], [40.0, 60.0, 'c'], [60.0, 80.0, 'd'], [80.0, 100.0, 'e']]);

    $chunks = Chunker::chunk($segments, maxSeconds: 60.0, splitOnSpeaker: false);

    expect(array_map(fn (Chunk $c) => $c->segmentIds, $chunks))->toBe([[0, 1, 2], [3, 4]])
        ->and(array_map(fn (Chunk $c) => $c->index, $chunks))->toBe([0, 1])
        ->and($chunks[1]->start)->toBe(60.0);
});

test('character limit closes a chunk', function () {
    $segments = segs([[0.0, 1.0, 'aaaa'], [1.0, 2.0, 'bbbb'], [2.0, 3.0, 'cccc']]);

    $chunks = Chunker::chunk($segments, maxCharacters: 9);

    expect(array_map(fn (Chunk $c) => $c->text, $chunks))->toBe(['aaaa bbbb', 'cccc']);
});

test('characters are counted as characters, not bytes', function () {
    $chunks = Chunker::chunk(segs([[0.0, 1.0, 'ääää'], [1.0, 2.0, 'öööö']]), maxCharacters: 9);

    expect($chunks)->toHaveCount(1);
});

test('a segment longer than the limits becomes its own chunk and is never split', function () {
    $segments = segs([[0.0, 1.0, 'short'], [1.0, 200.0, 'a very long monologue'], [200.0, 201.0, 'tail']]);

    $chunks = Chunker::chunk($segments, maxSeconds: 30.0, splitOnSpeaker: false);

    expect(array_map(fn (Chunk $c) => $c->segmentIds, $chunks))->toBe([[0], [1], [2]])
        ->and($chunks[1]->getDuration())->toBe(199.0);
});

test('speaker changes close a chunk and chunks list their speaker', function () {
    $segments = segs([
        [0.0, 2.0, 'Hi.', 'SPEAKER_00'],
        [2.0, 4.0, 'Welcome.', 'SPEAKER_00'],
        [4.0, 6.0, 'Thanks.', 'SPEAKER_01'],
        [6.0, 8.0, 'Sure.', 'SPEAKER_00'],
    ]);

    $chunks = Chunker::chunk($segments);

    expect(array_map(fn (Chunk $c) => $c->segmentIds, $chunks))->toBe([[0, 1], [2], [3]])
        ->and($chunks[0]->getSpeaker())->toBe('SPEAKER_00')
        ->and($chunks[1]->speakers)->toBe(['SPEAKER_01']);
});

test('speakers can be ignored and are then all listed', function () {
    $segments = segs([[0.0, 2.0, 'Hi.', 'SPEAKER_00'], [2.0, 4.0, 'Thanks.', 'SPEAKER_01']]);

    $chunks = Chunker::chunk($segments, splitOnSpeaker: false);

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->speakers)->toBe(['SPEAKER_00', 'SPEAKER_01'])
        ->and($chunks[0]->getSpeaker())->toBeNull();
});

test('a pause closes a chunk', function () {
    $segments = segs([[0.0, 2.0, 'one'], [2.5, 4.0, 'two'], [9.0, 10.0, 'three']]);

    $chunks = Chunker::chunk($segments, pauseSeconds: 3.0);

    expect(array_map(fn (Chunk $c) => $c->segmentIds, $chunks))->toBe([[0, 1], [2]]);
});

test('overlap repeats the last segments after a size break', function () {
    $segments = segs([[0.0, 10.0, 'a'], [10.0, 20.0, 'b'], [20.0, 30.0, 'c'], [30.0, 40.0, 'd'], [40.0, 50.0, 'e']]);

    $chunks = Chunker::chunk($segments, maxSeconds: 20.0, overlapSegments: 1, splitOnSpeaker: false);

    expect(array_map(fn (Chunk $c) => $c->segmentIds, $chunks))->toBe([[0, 1], [1, 2], [2, 3], [3, 4]])
        ->and($chunks[1]->start)->toBe(10.0);
});

test('overlap is not carried over a speaker change or a pause', function () {
    $bySpeaker = Chunker::chunk(
        segs([[0.0, 2.0, 'a', 'S0'], [2.0, 4.0, 'b', 'S1']]),
        overlapSegments: 2,
    );
    $byPause = Chunker::chunk(segs([[0.0, 2.0, 'a'], [20.0, 22.0, 'b']]), overlapSegments: 2, pauseSeconds: 5.0);

    expect(array_map(fn (Chunk $c) => $c->segmentIds, $bySpeaker))->toBe([[0], [1]])
        ->and(array_map(fn (Chunk $c) => $c->segmentIds, $byPause))->toBe([[0], [1]]);
});

test('overlap that cannot fit with the next segment is dropped and the chunker always advances', function () {
    $segments = segs([[0.0, 15.0, 'a'], [15.0, 30.0, 'b'], [30.0, 80.0, 'huge'], [80.0, 90.0, 'c']]);

    $chunks = Chunker::chunk($segments, maxSeconds: 30.0, overlapSegments: 3, splitOnSpeaker: false);

    expect(array_map(fn (Chunk $c) => $c->segmentIds, $chunks))->toBe([[0, 1], [2], [3]]);
});

test('every segment lands in at least one chunk and chunks stay in order', function () {
    $rows = [];

    for ($i = 0; $i < 60; $i++) {
        $rows[] = [$i * 3.0, $i * 3.0 + 2.5, str_repeat('word ', 1 + $i % 7)];
    }

    $chunks = Chunker::chunk(segs($rows), maxSeconds: 20.0, maxCharacters: 60, overlapSegments: 1);
    $seen = array_unique(array_merge(...array_map(fn (Chunk $c) => $c->segmentIds, $chunks)));

    expect(count($seen))->toBe(60)
        ->and(array_map(fn (Chunk $c) => $c->start, $chunks))->toBe(array_values(array_map(fn (Chunk $c) => $c->start, $chunks)));

    foreach ($chunks as $i => $chunk) {
        expect($chunk->index)->toBe($i)
            ->and($chunk->end)->toBeGreaterThan($chunk->start);
    }
});

test('invalid limits are rejected', function (array $args) {
    expect(fn () => Chunker::chunk([], ...$args))->toThrow(InvalidArgumentException::class);
})->with([[['maxSeconds' => 0.0]], [['maxCharacters' => 0]], [['overlapSegments' => -1]], [['pauseSeconds' => 0.0]]]);

test('Transcript::chunks delegates and getChunkUrl links to the chunk start', function () {
    $t = transcriptWith(segs([[0.0, 30.0, 'a'], [30.0, 70.0, 'b'], [70.0, 100.0, 'c']]), 'https://a.test/ep.mp3#t=1');

    $chunks = $t->chunks(maxSeconds: 80.0);

    expect($chunks)->toHaveCount(2)
        ->and($t->getChunkUrl($chunks[1]))->toBe('https://a.test/ep.mp3#t=70');
});

test('a chunk serializes to snake_case json', function () {
    $chunk = new Chunk(2, 1.5, 4.0, 'Hi there.', [3, 4], ['SPEAKER_00']);

    expect(json_decode(json_encode($chunk), true))->toEqual([
        'index' => 2,
        'start' => 1.5,
        'end' => 4.0,
        'text' => 'Hi there.',
        'segment_ids' => [3, 4],
        'speakers' => ['SPEAKER_00'],
    ]);
});
