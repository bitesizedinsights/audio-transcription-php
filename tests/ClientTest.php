<?php

declare(strict_types=1);

use AudioTranscription\Client;
use AudioTranscription\Config;
use AudioTranscription\Exception\ApiException;
use AudioTranscription\Exception\MissingApiKeyException;
use AudioTranscription\Exception\RateLimitException;
use AudioTranscription\Language;
use AudioTranscription\SubtitleFormat;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/** @return array<string, mixed> */
function sentBody(RequestInterface $request): array
{
    /** @var array<string, mixed> */
    return json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
}

test('transcribe runs the actor and maps the dataset', function () {
    $history = [];
    $client = makeClient([
        runResponse(),
        jsonResponse(200, [loadFixture('transcript_full'), loadFixture('transcript_failed')]),
    ], $history);

    $result = $client->transcribe(['https://example.com/podcast.mp3', 'https://example.com/page.html']);

    expect($result)->toHaveCount(2)
        ->and($result[0]->isSuccessful())->toBeTrue()
        ->and($result[1]->isFailed())->toBeTrue()
        ->and($history)->toHaveCount(2);

    $post = $history[0]['request'];
    expect($post->getMethod())->toBe('POST')
        ->and($post->getUri()->getPath())->toBe('/v2/acts/bite-sized-insights~transcription/runs')
        ->and($post->getHeaderLine('Authorization'))->toBe('Bearer test-token')
        ->and(sentBody($post))->toBe([
            'urls' => ['https://example.com/podcast.mp3', 'https://example.com/page.html'],
            'language' => 'auto',
            'maxMinutesPerFile' => 60,
        ]);

    $get = $history[1]['request'];
    expect($get->getMethod())->toBe('GET')
        ->and($get->getUri()->getPath())->toBe('/v2/datasets/ds1/items')
        ->and($get->getHeaderLine('Authorization'))->toBe('Bearer test-token');
});

test('transcribe sends all options', function () {
    $history = [];
    $client = makeClient([runResponse(), jsonResponse(200, [])], $history);

    $client->transcribe(
        urls: ['https://example.com/a.mp3'],
        language: Language::German,
        translateToEnglish: true,
        subtitleFormats: [SubtitleFormat::Srt, SubtitleFormat::Vtt],
        diarize: true,
        numSpeakers: 3,
        maxMinutesPerFile: 15,
        withSummary: true,
        withTags: true,
        with5w1h: true,
        withSentiment: true,
    );

    expect(sentBody($history[0]['request']))->toBe([
        'urls' => ['https://example.com/a.mp3'],
        'language' => 'de',
        'maxMinutesPerFile' => 15,
        'translateToEnglish' => true,
        'subtitleFormats' => ['srt', 'vtt'],
        'diarize' => true,
        'numSpeakers' => 3,
        'withSummary' => true,
        'withTags' => true,
        'with5w1h' => true,
        'withSentiment' => true,
    ]);
});

test('optional input keys are omitted when unset', function () {
    $history = [];
    $client = makeClient([runResponse(), jsonResponse(200, [])], $history);

    $client->transcribe(['https://example.com/a.mp3']);

    expect(sentBody($history[0]['request']))->not->toHaveKeys([
        'translateToEnglish', 'subtitleFormats', 'diarize', 'numSpeakers',
        'withSummary', 'withTags', 'with5w1h', 'withSentiment',
    ]);
});

test('empty dataset gives an empty list', function () {
    $client = makeClient([runResponse(), jsonResponse(200, [])]);

    expect($client->transcribe(['https://example.com/a.mp3']))->toBe([]);
});

test('waits for a running run until it succeeds', function () {
    $history = [];
    $client = makeClient([
        runResponse('RUNNING', 'run42'),
        runResponse('RUNNING', 'run42'),
        runResponse('SUCCEEDED', 'run42'),
        jsonResponse(200, [loadFixture('transcript_full')]),
    ], $history);

    $result = $client->transcribe(['https://example.com/podcast.mp3']);

    expect($result)->toHaveCount(1)
        ->and($history)->toHaveCount(4)
        ->and($history[1]['request']->getUri()->getPath())->toBe('/v2/actor-runs/run42')
        ->and($history[1]['request']->getUri()->getQuery())->toStartWith('waitForFinish=');
});

test('throws when the run does not finish in time', function () {
    $client = makeClient([runResponse('RUNNING')], config: new Config(timeout: 0));

    expect(fn () => $client->transcribe(['https://example.com/a.mp3']))
        ->toThrow(ApiException::class, 'did not finish within 0 seconds');
});

test('throws on failed run status', function (string $status) {
    $client = makeClient([runResponse($status)]);

    expect(fn () => $client->transcribe(['https://example.com/a.mp3']))
        ->toThrow(ApiException::class, "status {$status}");
})->with(['FAILED', 'ABORTED', 'TIMED-OUT']);

test('throws on unknown run status', function () {
    $client = makeClient([runResponse('WEIRD')]);

    expect(fn () => $client->transcribe(['https://example.com/a.mp3']))
        ->toThrow(ApiException::class, 'unknown run status');
});

test('throws when a running run has no id', function () {
    $client = makeClient([jsonResponse(201, ['data' => ['status' => 'RUNNING']])]);

    expect(fn () => $client->transcribe(['https://example.com/a.mp3']))
        ->toThrow(ApiException::class, 'missing run ID');
});

test('throws when the run data is missing', function () {
    $client = makeClient([jsonResponse(201, ['foo' => 'bar'])]);

    expect(fn () => $client->transcribe(['https://example.com/a.mp3']))
        ->toThrow(ApiException::class, 'missing run data');
});

test('throws when the dataset id is missing', function () {
    $client = makeClient([runResponse(datasetId: null)]);

    expect(fn () => $client->transcribe(['https://example.com/a.mp3']))
        ->toThrow(ApiException::class, 'missing dataset ID');
});

test('429 maps to RateLimitException with Retry-After', function () {
    $client = makeClient([jsonResponse(429, ['error' => 'slow down'], ['Retry-After' => '12'])]);

    try {
        $client->transcribe(['https://example.com/a.mp3']);
        $this->fail('Expected RateLimitException');
    } catch (RateLimitException $e) {
        expect($e->retryAfter)->toBe(12)
            ->and($e)->toBeInstanceOf(ApiException::class);
    }
});

test('429 without Retry-After defaults to 60 seconds', function () {
    $client = makeClient([jsonResponse(429, ['error' => 'slow down'])]);

    try {
        $client->transcribe(['https://example.com/a.mp3']);
        $this->fail('Expected RateLimitException');
    } catch (RateLimitException $e) {
        expect($e->retryAfter)->toBe(60);
    }
});

test('500 maps to ApiException with the status code', function () {
    $client = makeClient([new Response(500, [], 'boom')]);

    try {
        $client->transcribe(['https://example.com/a.mp3']);
        $this->fail('Expected ApiException');
    } catch (ApiException $e) {
        expect($e)->not->toBeInstanceOf(RateLimitException::class)
            ->and($e->getCode())->toBe(500)
            ->and($e->getMessage())->toContain('status 500');
    }
});

test('connection errors map to ApiException', function () {
    $client = makeClient([new ConnectException('no route', new Request('POST', 'https://api.apify.com'))]);

    expect(fn () => $client->transcribe(['https://example.com/a.mp3']))
        ->toThrow(ApiException::class, 'API request failed: no route');
});

test('malformed JSON maps to ApiException', function () {
    $client = makeClient([new Response(200, [], '{not json')]);

    expect(fn () => $client->transcribe(['https://example.com/a.mp3']))
        ->toThrow(ApiException::class, 'malformed JSON');
});

test('non-object JSON maps to ApiException', function () {
    $client = makeClient([new Response(200, [], '"text"')]);

    expect(fn () => $client->transcribe(['https://example.com/a.mp3']))
        ->toThrow(ApiException::class, 'unexpected JSON');
});

test('invalid dataset item names its index', function () {
    $broken = [...loadFixture('transcript_full'), 'status' => 'weird'];
    $client = makeClient([runResponse(), jsonResponse(200, [loadFixture('transcript_full'), $broken])]);

    expect(fn () => $client->transcribe(['https://example.com/a.mp3']))
        ->toThrow(ApiException::class, 'Invalid dataset item at index 1');
});

test('non-object dataset item names its index', function () {
    $client = makeClient([runResponse(), jsonResponse(200, ['text'])]);

    expect(fn () => $client->transcribe(['https://example.com/a.mp3']))
        ->toThrow(ApiException::class, 'Invalid dataset item at index 0');
});

test('custom config changes actor, base url and request timeout', function () {
    $history = [];
    $config = new Config('acme/custom', 'https://proxy.example.com/v2/', requestTimeout: 5);
    $client = makeClient([runResponse(), jsonResponse(200, [])], $history, $config);

    $client->transcribe(['https://example.com/a.mp3']);

    $request = $history[0]['request'];
    expect($request->getUri()->getHost())->toBe('proxy.example.com')
        ->and($request->getUri()->getPath())->toBe('/v2/acts/acme~custom/runs')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer test-token');
});

test('client works with its default HTTP client', function () {
    expect(new Client)->toBeInstanceOf(Client::class);
});

test('setApifyKey is fluent and sets the bearer token', function () {
    $history = [];
    $client = makeClient([runResponse(), jsonResponse(200, [])], $history, apiKey: null);

    expect($client->setApifyKey('my-key'))->toBe($client);

    $client->transcribe(['https://example.com/a.mp3']);

    expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer my-key');
});

test('falls back to the APIFY_API_TOKEN and APIFY_TOKEN environment variables', function () {
    $previous = [getenv('APIFY_API_TOKEN'), getenv('APIFY_TOKEN')];

    try {
        putenv('APIFY_API_TOKEN');
        putenv('APIFY_TOKEN=env-token-2');
        $history = [];
        makeClient([runResponse(), jsonResponse(200, [])], $history, apiKey: null)->transcribe(['https://example.com/a.mp3']);
        expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer env-token-2');

        putenv('APIFY_API_TOKEN=env-token-1');
        $history = [];
        makeClient([runResponse(), jsonResponse(200, [])], $history, apiKey: null)->transcribe(['https://example.com/a.mp3']);
        expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer env-token-1');
    } finally {
        putenv($previous[0] === false ? 'APIFY_API_TOKEN' : 'APIFY_API_TOKEN='.$previous[0]);
        putenv($previous[1] === false ? 'APIFY_TOKEN' : 'APIFY_TOKEN='.$previous[1]);
    }
});

test('throws MissingApiKeyException when no key is set anywhere', function () {
    $previous = [getenv('APIFY_API_TOKEN'), getenv('APIFY_TOKEN')];

    try {
        putenv('APIFY_API_TOKEN');
        putenv('APIFY_TOKEN');
        $history = [];
        $client = makeClient([], $history, apiKey: null);

        expect(fn () => $client->transcribe(['https://example.com/a.mp3']))->toThrow(MissingApiKeyException::class)
            ->and($history)->toBeEmpty();
    } finally {
        putenv($previous[0] === false ? 'APIFY_API_TOKEN' : 'APIFY_API_TOKEN='.$previous[0]);
        putenv($previous[1] === false ? 'APIFY_TOKEN' : 'APIFY_TOKEN='.$previous[1]);
    }
});
