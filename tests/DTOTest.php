<?php

declare(strict_types=1);

use AudioTranscription\DiarizationStatus;
use AudioTranscription\DTO\Segment;
use AudioTranscription\DTO\SentimentResult;
use AudioTranscription\DTO\Summary;
use AudioTranscription\DTO\Tags;
use AudioTranscription\DTO\Transcript;
use AudioTranscription\ErrorCode;
use AudioTranscription\Sentiment;
use AudioTranscription\TranscriptStatus;

test('transcript from array with all fields', function () {
    $t = Transcript::fromArray(loadFixture('transcript_full'));

    expect($t->url)->toBe('https://example.com/podcast.mp3')
        ->and($t->status)->toBe(TranscriptStatus::Success)
        ->and($t->language)->toBe('en')
        ->and($t->languageProbability)->toBe(0.99)
        ->and($t->durationSeconds)->toBe(84.3)
        ->and($t->minutesTranscribed)->toBe(2)
        ->and($t->text)->toStartWith('Welcome')
        ->and($t->segments)->toHaveCount(2)
        ->and($t->segments[1]->text)->toBe('Today we talk about timestamps.')
        ->and($t->speakers)->toBe(2)
        ->and($t->diarization)->toBe(DiarizationStatus::Ok)
        ->and($t->srt)->toContain('00:00:00,000 --> 00:00:02,400')
        ->and($t->vtt)->toBeNull()
        ->and($t->fiveW1h)->toHaveKey('who')
        ->and($t->analysisTruncated)->toBeFalse();
});

test('transcript helpers with add-ons', function () {
    $t = Transcript::fromArray(loadFixture('transcript_full'));

    expect($t->isSuccessful())->toBeTrue()
        ->and($t->isPartial())->toBeFalse()
        ->and($t->isFailed())->toBeFalse()
        ->and($t->getErrorCode())->toBeNull()
        ->and($t->getSummaryText())->toBe('A short welcome and an introduction to timestamps.')
        ->and($t->getTags())->toBe(['podcast', 'timestamps'])
        ->and($t->getSentiment())->toBe(Sentiment::Positive)
        ->and($t->sentiment?->confidence)->toBe(0.82)
        ->and($t->getSegmentCount())->toBe(2);
});

test('failed transcript', function () {
    $t = Transcript::fromArray(loadFixture('transcript_failed'));

    expect($t->isFailed())->toBeTrue()
        ->and($t->text)->toBeNull()
        ->and($t->segments)->toBe([])
        ->and($t->errorCode)->toBe('unsupported_format')
        ->and($t->getErrorCode())->toBe(ErrorCode::UnsupportedFormat)
        ->and($t->error)->toStartWith('Unsupported file type')
        ->and($t->getSummaryText())->toBeNull()
        ->and($t->getTags())->toBe([])
        ->and($t->getSentiment())->toBeNull();
});

test('transcript with only required fields', function () {
    $t = Transcript::fromArray(loadFixture('transcript_minimal'));

    expect($t->status)->toBe(TranscriptStatus::Partial)
        ->and($t->isPartial())->toBeTrue()
        ->and($t->translated)->toBeTrue()
        ->and($t->truncated)->toBeTrue()
        ->and($t->transcribedSeconds)->toBe(30.0)
        ->and($t->language)->toBeNull()
        ->and($t->diarization)->toBeNull()
        ->and($t->summary)->toBeNull()
        ->and($t->tags)->toBeNull()
        ->and($t->fiveW1h)->toBeNull()
        ->and($t->analysisTruncated)->toBeFalse();
});

test('unknown error code and diarization outcome give null', function () {
    $t = Transcript::fromArray([
        ...loadFixture('transcript_failed'),
        'error_code' => 'brand_new',
        'diarization' => 'brand_new',
    ]);

    expect($t->errorCode)->toBe('brand_new')
        ->and($t->getErrorCode())->toBeNull()
        ->and($t->diarization)->toBeNull();
});

test('unknown sentiment label gives null sentiment', function () {
    $t = Transcript::fromArray([
        ...loadFixture('transcript_minimal'),
        'sentiment' => ['sentiment' => 'mixed'],
    ]);

    expect($t->sentiment?->confidence)->toBeNull()
        ->and($t->getSentiment())->toBeNull();
});

test('transcript rejects invalid data', function (array $override, string $message) {
    expect(fn () => Transcript::fromArray([...loadFixture('transcript_minimal'), ...$override]))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown status' => [['status' => 'weird'], "Unknown status 'weird'"],
    'missing url' => [['url' => null], "Field 'url'"],
    'bad segments' => [['segments' => ['x']], 'must contain objects'],
    'bad minutes' => [['minutes_transcribed' => '2'], "Field 'minutes_transcribed'"],
    'bad flag' => [['truncated' => 'yes'], "Field 'truncated'"],
    'bad number' => [['transcribed_seconds' => 'x'], "Field 'transcribed_seconds'"],
    'bad nullable string' => [['text' => 5], "Field 'text'"],
    'bad nullable int' => [['speakers' => 'x'], "Field 'speakers'"],
    'bad nullable float' => [['duration_seconds' => 'x'], "Field 'duration_seconds'"],
    'bad object' => [['summary' => 'x'], "Field 'summary'"],
    'segments missing' => [['segments' => null], "Field 'segments'"],
]);

test('segment from array and duration', function () {
    $s = Segment::fromArray(['id' => 3, 'start' => 1, 'end' => 3.5, 'text' => 'Hi']);

    expect($s->id)->toBe(3)
        ->and($s->start)->toBe(1.0)
        ->and($s->getDuration())->toBe(2.5);
});

test('summary without stats', function () {
    $s = Summary::fromArray(['summary' => 'Short.']);

    expect($s->summary)->toBe('Short.')
        ->and($s->originalLength)->toBeNull()
        ->and($s->summaryLength)->toBeNull()
        ->and($s->compressionRatio)->toBeNull();
});

test('summary with stats', function () {
    $s = Summary::fromArray(loadFixture('transcript_full')['summary']);

    expect($s->originalLength)->toBe(52)
        ->and($s->summaryLength)->toBe(50)
        ->and($s->compressionRatio)->toBe(0.9615);
});

test('tags count falls back to the list size', function () {
    expect(Tags::fromArray(['tags' => ['a', 'b', 'c']])->count)->toBe(3)
        ->and(Tags::fromArray(['tags' => ['a'], 'count' => 7])->count)->toBe(7);
});

test('tags reject non-strings', function () {
    expect(fn () => Tags::fromArray(['tags' => [1]]))->toThrow(InvalidArgumentException::class);
});

test('segment speaker is read when present and null otherwise', function () {
    $labelled = Segment::fromArray(['id' => 0, 'start' => 0.0, 'end' => 1.0, 'text' => 'Hi.', 'speaker' => 'SPEAKER_01']);
    $plain = Segment::fromArray(['id' => 1, 'start' => 1.0, 'end' => 2.0, 'text' => 'Hello.']);
    $nulled = Segment::fromArray(['id' => 2, 'start' => 2.0, 'end' => 3.0, 'text' => 'Hey.', 'speaker' => null]);

    expect($labelled->speaker)->toBe('SPEAKER_01')
        ->and($plain->speaker)->toBeNull()
        ->and($nulled->speaker)->toBeNull();
});

test('transcript round trips through toArray and json', function (string $fixture) {
    $transcript = Transcript::fromArray(loadFixture($fixture));

    $viaArray = Transcript::fromArray($transcript->toArray());
    $viaJson = Transcript::fromArray(json_decode(json_encode($transcript, JSON_THROW_ON_ERROR), true));

    expect($viaArray)->toEqual($transcript)
        ->and($viaJson)->toEqual($transcript);
})->with(['transcript_full', 'transcript_minimal', 'transcript_failed']);

test('toArray uses the Actor field names', function () {
    $array = Transcript::fromArray(loadFixture('transcript_full'))->toArray();

    expect($array)->toHaveKeys(['transcribed_seconds', 'minutes_transcribed', 'language_probability', 'five_w1h'])
        ->and($array['status'])->toBe('success')
        ->and($array['segments'][0])->toHaveKeys(['id', 'start', 'end', 'text', 'speaker']);
});

test('segment with a speaker round trips', function () {
    $segment = new Segment(3, 1.5, 2.5, 'Hi.', 'SPEAKER_01');

    expect(Segment::fromArray($segment->toArray()))->toEqual($segment)
        ->and(json_encode($segment))->toContain('"speaker":"SPEAKER_01"');
});

test('unknown sentiment label is stored and read back as null', function () {
    $result = SentimentResult::fromArray(['sentiment' => 'ecstatic', 'confidence' => 0.5]);

    expect($result->sentiment)->toBeNull()
        ->and(SentimentResult::fromArray($result->toArray())->sentiment)->toBeNull();
});

test('segment timestamp is m:ss or h:mm:ss', function (float $start, string $expected) {
    expect((new Segment(0, $start, $start + 1, 'x'))->getTimestamp())->toBe($expected);
})->with([[0.0, '0:00'], [5.9, '0:05'], [65.0, '1:05'], [3599.9, '59:59'], [3723.0, '1:02:03']]);

test('segment url and timestamped text', function () {
    $t = Transcript::fromArray(loadFixture('transcript_full'));
    $second = $t->segments[1];

    expect($t->getSegmentUrl($second))->toBe('https://example.com/podcast.mp3#t='.(int) floor($second->start))
        ->and(Transcript::fromArray(['url' => 'https://a.test/x.mp3#t=9'] + loadFixture('transcript_full'))
            ->getSegmentUrl($second))->toBe('https://a.test/x.mp3#t='.(int) floor($second->start))
        ->and($t->getTimestampedText())->toStartWith('[0:00] ')
        ->and(substr_count($t->getTimestampedText(), "\n"))->toBe(count($t->segments) - 1);
});

test('timestamped text includes speaker labels', function () {
    $t = new Transcript('https://a.test/x.mp3', TranscriptStatus::Success, false, 5.0, 1, false, [
        new Segment(0, 0.0, 2.0, 'Hello.', 'SPEAKER_00'),
        new Segment(1, 62.0, 64.0, 'Hi.', 'SPEAKER_01'),
    ]);

    expect($t->getTimestampedText())->toBe("[0:00] SPEAKER_00: Hello.\n[1:02] SPEAKER_01: Hi.");
});

test('saveSrt and saveVtt write the subtitles or explain what is missing', function () {
    $full = Transcript::fromArray(loadFixture('transcript_full'));
    $dir = sys_get_temp_dir().'/at-'.bin2hex(random_bytes(4));
    mkdir($dir);

    $full->saveSrt("{$dir}/a.srt");
    expect(file_get_contents("{$dir}/a.srt"))->toBe($full->srt);

    expect(fn () => $full->saveVtt("{$dir}/a.vtt"))->toThrow(RuntimeException::class, 'No WebVTT')
        ->and(fn () => $full->saveSrt("{$dir}/missing/a.srt"))->toThrow(RuntimeException::class, 'Cannot write');

    unlink("{$dir}/a.srt");
    rmdir($dir);
});
