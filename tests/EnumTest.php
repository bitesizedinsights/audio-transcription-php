<?php

declare(strict_types=1);

use AudioTranscription\DiarizationStatus;
use AudioTranscription\ErrorCode;
use AudioTranscription\Language;
use AudioTranscription\RunStatus;
use AudioTranscription\Sentiment;
use AudioTranscription\SubtitleFormat;
use AudioTranscription\TranscriptStatus;

test('enum values', function (string $enum, array $values) {
    expect(array_map(static fn ($case) => $case->value, $enum::cases()))->toBe($values);
})->with([
    'subtitle format' => [SubtitleFormat::class, ['srt', 'vtt']],
    'status' => [TranscriptStatus::class, ['success', 'partial', 'skipped', 'failed']],
    'diarization' => [DiarizationStatus::class, ['ok', 'skipped_time', 'failed']],
    'sentiment' => [Sentiment::class, ['positive', 'neutral', 'negative']],
    'error code' => [ErrorCode::class, [
        'blocked_url', 'download_failed', 'too_large', 'unsupported_format', 'invalid_media',
        'no_audio', 'too_long', 'no_speech', 'transcription_failed', 'service_unavailable',
        'run_time_limit', 'invalid_feed', 'no_episodes',
    ]],
]);

test('unknown enum values give null', function (string $enum) {
    expect($enum::tryFrom('nope'))->toBeNull();
})->with([
    SubtitleFormat::class, TranscriptStatus::class, DiarizationStatus::class,
    Sentiment::class, ErrorCode::class, Language::class,
]);

test('language has auto plus 100 languages with unique values', function () {
    $values = array_map(static fn (Language $l) => $l->value, Language::cases());

    expect($values)->toHaveCount(101)
        ->and(array_unique($values))->toHaveCount(101)
        ->and(Language::from('auto'))->toBe(Language::Auto)
        ->and(Language::from('de'))->toBe(Language::German)
        ->and(Language::from('yue'))->toBe(Language::Cantonese)
        ->and(Language::from('ht'))->toBe(Language::HaitianCreole);
});

test('run status knows which states are final', function () {
    expect(RunStatus::Succeeded->isFinished())->toBeTrue()
        ->and(RunStatus::Succeeded->isFailure())->toBeFalse()
        ->and(RunStatus::Failed->isFailure())->toBeTrue()
        ->and(RunStatus::Aborted->isFailure())->toBeTrue()
        ->and(RunStatus::TimedOut->isFailure())->toBeTrue()
        ->and(array_filter(RunStatus::cases(), static fn (RunStatus $s): bool => ! $s->isFinished()))
        ->toHaveCount(4)
        ->and(RunStatus::tryFrom('TIMING-OUT'))->toBe(RunStatus::TimingOut);
});
