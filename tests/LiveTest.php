<?php

declare(strict_types=1);

use AudioTranscription\Client;
use AudioTranscription\Config;
use AudioTranscription\Language;
use AudioTranscription\SubtitleFormat;

/**
 * Live test against the real Apify Actor. Costs a few cents.
 *
 * Runs only with APIFY_LIVE_TEST=1 and a token in APIFY_API_TOKEN or APIFY_TOKEN.
 */
const LIVE_SAMPLE_URL = 'https://upload.wikimedia.org/wikipedia/commons/d/dd/Armstrong_Small_Step.ogg';

function liveToken(): ?string
{
    foreach (['APIFY_API_TOKEN', 'APIFY_TOKEN'] as $name) {
        $value = getenv($name);

        if (is_string($value) && $value !== '') {
            return $value;
        }
    }

    return null;
}

function liveEnabled(): bool
{
    return getenv('APIFY_LIVE_TEST') === '1' && liveToken() !== null;
}

/**
 * Fetches the sample file once and keeps it in the system temp directory.
 */
function liveSampleFile(): string
{
    $path = sys_get_temp_dir().'/audio-transcription-live-'.md5(LIVE_SAMPLE_URL).'.ogg';

    if (! is_file($path) || filesize($path) === 0) {
        $context = stream_context_create(['http' => ['timeout' => 60, 'user_agent' => 'audio-transcription-php-tests']]);
        $data = file_get_contents(LIVE_SAMPLE_URL, false, $context);

        if ($data === false || $data === '') {
            throw new RuntimeException('Could not fetch the live test sample file');
        }

        file_put_contents($path, $data);
    }

    return $path;
}

test('live: transcribes a real recording', function () {
    expect(filesize(liveSampleFile()))->toBeGreaterThan(0);

    $token = liveToken();
    assert($token !== null);

    $client = (new Client(new Config(timeout: 600)))->setApifyKey($token);

    $result = $client->transcribe(
        urls: [LIVE_SAMPLE_URL],
        language: Language::English,
        subtitleFormats: [SubtitleFormat::Srt],
        maxMinutesPerFile: 1,
    );

    expect($result)->toHaveCount(1);

    $transcript = $result[0];

    expect($transcript->isFailed())->toBeFalse($transcript->error ?? '')
        ->and($transcript->text)->toBeString()->not->toBeEmpty()
        ->and(strtolower((string) $transcript->text))->toContain('step')
        ->and($transcript->segments)->not->toBeEmpty()
        ->and($transcript->minutesTranscribed)->toBeGreaterThan(0)
        ->and($transcript->srt)->toBeString()->toContain('-->');
})->skip(fn () => ! liveEnabled(), 'Set APIFY_LIVE_TEST=1 and APIFY_API_TOKEN (or APIFY_TOKEN) to run live tests');
