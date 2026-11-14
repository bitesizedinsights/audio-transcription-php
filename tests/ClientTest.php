<?php

declare(strict_types=1);

use AudioTranscription\Client;
use AudioTranscription\Config;
use AudioTranscription\DTO\Run;
use AudioTranscription\Exception\ApiException;
use AudioTranscription\Exception\MissingApiKeyException;
use AudioTranscription\Exception\RateLimitException;
use AudioTranscription\Language;
use AudioTranscription\RunStatus;
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

test('a language code string is passed through trimmed', function (string $given, string $sent) {
    $history = [];
    $client = makeClient([runResponse(), jsonResponse(200, [])], $history);

    $client->transcribe(['https://example.com/a.mp3'], language: $given);

    expect(sentBody($history[0]['request'])['language'])->toBe($sent);
})->with([
    'regional code' => ['pt-BR', 'pt-BR'],
    'plain code' => ['ja', 'ja'],
    'padded' => ['  de ', 'de'],
    'empty is auto' => ['', 'auto'],
    'blank is auto' => ['   ', 'auto'],
]);

test('the Language enum still works, also with start()', function () {
    $history = [];
    $client = makeClient([runResponse(), runResponse(), jsonResponse(200, [])], $history);

    $client->start(['https://example.com/a.mp3'], language: Language::Cantonese);
    $client->start(['https://example.com/a.mp3'], language: Language::Auto);

    expect(sentBody($history[0]['request'])['language'])->toBe('yue')
        ->and(sentBody($history[1]['request'])['language'])->toBe('auto');
});

test('includeSegments is omitted by default and sent only when false', function () {
    $history = [];
    $client = makeClient([runResponse(), jsonResponse(200, []), runResponse(), runResponse()], $history);

    $client->transcribe(['https://example.com/a.mp3']);
    $client->start(['https://example.com/a.mp3'], includeSegments: false);
    $client->start(['https://example.com/a.mp3'], includeSegments: true);

    expect(sentBody($history[0]['request']))->not->toHaveKey('includeSegments')
        ->and(sentBody($history[2]['request']))->toHaveKey('includeSegments', false)
        ->and(sentBody($history[3]['request']))->not->toHaveKey('includeSegments');
});

test('includeSegments false works for feeds via transcribe()', function () {
    $history = [];
    $client = makeClient([runResponse(), jsonResponse(200, [])], $history);

    $client->transcribe(feedUrls: ['https://example.com/feed.xml'], includeSegments: false);

    expect(sentBody($history[0]['request']))->toHaveKey('includeSegments', false);
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

test('429 is retried after the Retry-After wait and then succeeds', function () {
    $history = [];
    $sleeps = [];
    $client = makeClient([
        jsonResponse(429, ['error' => 'slow'], ['Retry-After' => '5']),
        runResponse(),
        jsonResponse(200, []),
    ], $history, new Config, sleeps: $sleeps);

    expect($client->transcribe(['https://example.com/a.mp3']))->toBe([])
        ->and($sleeps)->toHaveCount(1)
        ->and($sleeps[0])->toBeGreaterThanOrEqual(5.0)
        ->and($history)->toHaveCount(3);
});

test('429 with a Retry-After above the maximum delay is not waited out', function () {
    $sleeps = [];
    $client = makeClient([jsonResponse(429, [], ['Retry-After' => '120'])], config: new Config, sleeps: $sleeps);

    expect(fn () => $client->transcribe(['https://example.com/a.mp3']))->toThrow(RateLimitException::class)
        ->and($sleeps)->toBe([]);
});

test('GET requests retry on 5xx with growing backoff, then give up', function () {
    $sleeps = [];
    $client = makeClient([
        runResponse('RUNNING'),
        new Response(503, [], 'a'),
        new Response(502, [], 'b'),
        new Response(500, [], 'c'),
        new Response(500, [], 'd'),
    ], config: new Config(maxRetries: 3), sleeps: $sleeps);

    expect(fn () => $client->transcribe(['https://example.com/a.mp3']))
        ->toThrow(ApiException::class, 'status 500')
        ->and($sleeps)->toHaveCount(3)
        ->and($sleeps[0])->toBeBetween(0.5, 1.0)
        ->and($sleeps[1])->toBeBetween(1.0, 2.0)
        ->and($sleeps[2])->toBeBetween(2.0, 4.0);
});

test('starting a run is not repeated after a 5xx', function () {
    $history = [];
    $sleeps = [];
    $client = makeClient([new Response(500, [], 'boom'), runResponse()], $history, new Config, sleeps: $sleeps);

    expect(fn () => $client->transcribe(['https://example.com/a.mp3']))->toThrow(ApiException::class, 'status 500')
        ->and($history)->toHaveCount(1)
        ->and($sleeps)->toBe([]);
});

test('a failed connection is retried, also when starting a run', function () {
    $sleeps = [];
    $client = makeClient([
        new ConnectException('no route', new Request('POST', 'https://api.apify.com')),
        runResponse(),
        jsonResponse(200, []),
    ], config: new Config, sleeps: $sleeps);

    expect($client->transcribe(['https://example.com/a.mp3']))->toBe([])
        ->and($sleeps)->toHaveCount(1);
});

test('maxRetries 0 turns retrying off', function () {
    $history = [];
    $client = makeClient([jsonResponse(429, [], ['Retry-After' => '1']), runResponse()], $history);

    expect(fn () => $client->transcribe(['https://example.com/a.mp3']))->toThrow(RateLimitException::class)
        ->and($history)->toHaveCount(1);
});

test('4xx other than 429 is never retried', function () {
    $history = [];
    $client = makeClient([new Response(401, [], 'no'), runResponse()], $history, new Config);

    expect(fn () => $client->transcribe(['https://example.com/a.mp3']))->toThrow(ApiException::class, 'status 401')
        ->and($history)->toHaveCount(1);
});

test('feed urls are sent with the episode count and urls may be omitted', function () {
    $history = [];
    $client = makeClient([runResponse(), jsonResponse(200, [])], $history);

    $client->transcribe(feedUrls: ['https://feeds.example.com/show.xml'], maxEpisodesPerFeed: 3);

    expect(sentBody($history[0]['request']))->toBe([
        'language' => 'auto',
        'maxMinutesPerFile' => 60,
        'feedUrls' => ['https://feeds.example.com/show.xml'],
        'maxEpisodesPerFeed' => 3,
    ]);
});

test('urls and feeds can be combined', function () {
    $history = [];
    $client = makeClient([runResponse(), jsonResponse(200, [])], $history);

    $client->transcribe(['https://example.com/a.mp3'], feedUrls: ['https://feeds.example.com/show.xml']);

    expect(sentBody($history[0]['request']))->toMatchArray([
        'urls' => ['https://example.com/a.mp3'],
        'feedUrls' => ['https://feeds.example.com/show.xml'],
        'maxEpisodesPerFeed' => 5,
    ]);
});

test('the episode count is not sent without feeds', function () {
    $history = [];
    $client = makeClient([runResponse(), jsonResponse(200, [])], $history);

    $client->transcribe(['https://example.com/a.mp3'], maxEpisodesPerFeed: 9);

    expect(sentBody($history[0]['request']))->not->toHaveKeys(['feedUrls', 'maxEpisodesPerFeed']);
});

test('transcribe needs a url or a feed and a sane episode count', function () {
    $client = makeClient([]);

    expect(fn () => $client->transcribe())->toThrow(InvalidArgumentException::class, 'at least one URL')
        ->and(fn () => $client->transcribe(feedUrls: ['https://f.example/x.xml'], maxEpisodesPerFeed: 0))
        ->toThrow(InvalidArgumentException::class, 'between 1 and 50')
        ->and(fn () => $client->transcribe(feedUrls: ['https://f.example/x.xml'], maxEpisodesPerFeed: 51))
        ->toThrow(InvalidArgumentException::class, 'between 1 and 50');
});

test('the run timeout is sent as the timeout query of the run start only when set', function () {
    $history = [];
    $client = makeClient([runResponse(), jsonResponse(200, [])], $history, new Config(maxRetries: 0, runTimeout: 3600));

    $client->transcribe(['https://example.com/a.mp3']);

    expect($history[0]['request']->getUri()->getQuery())->toBe('timeout=3600')
        ->and($history[1]['request']->getUri()->getQuery())->not->toContain('timeout=3600');

    $history = [];
    makeClient([runResponse(), jsonResponse(200, [])], $history)->transcribe(['https://example.com/a.mp3']);

    expect($history[0]['request']->getUri()->getQuery())->toBe('');
});

test('start returns the run at once without polling or reading the dataset', function () {
    $history = [];
    $client = makeClient([runResponse('RUNNING', 'run7', 'ds7')], $history);

    $run = $client->start(['https://example.com/a.mp3'], language: Language::German);

    expect($run)->toBeInstanceOf(Run::class)
        ->and($run->id)->toBe('run7')
        ->and($run->status)->toBe(RunStatus::Running)
        ->and($run->defaultDatasetId)->toBe('ds7')
        ->and($history)->toHaveCount(1)
        ->and($history[0]['request']->getMethod())->toBe('POST')
        ->and(sentBody($history[0]['request']))->toBe([
            'urls' => ['https://example.com/a.mp3'],
            'language' => 'de',
            'maxMinutesPerFile' => 60,
        ]);
});

test('start validates its input before any request', function () {
    $history = [];
    $client = makeClient([], $history);

    expect(fn () => $client->start())->toThrow(InvalidArgumentException::class, 'at least one URL')
        ->and(fn () => $client->start(feedUrls: ['https://f.example/x.xml'], maxEpisodesPerFeed: 51))
        ->toThrow(InvalidArgumentException::class, 'between 1 and 50')
        ->and($history)->toBe([]);
});

test('getRun reads the run once without waiting', function () {
    $history = [];
    $client = makeClient([jsonResponse(200, ['data' => [
        'id' => 'run9',
        'status' => 'SUCCEEDED',
        'defaultDatasetId' => 'ds9',
        'startedAt' => '2026-11-11T10:00:00.000Z',
        'finishedAt' => '2026-11-11T10:05:00.000Z',
    ]])], $history);

    $run = $client->getRun('run9');

    expect($run->isSuccessful())->toBeTrue()
        ->and($run->startedAt)->toBe('2026-11-11T10:00:00.000Z')
        ->and($run->finishedAt)->toBe('2026-11-11T10:05:00.000Z')
        ->and($history)->toHaveCount(1)
        ->and($history[0]['request']->getUri()->getPath())->toBe('/v2/actor-runs/run9')
        ->and($history[0]['request']->getUri()->getQuery())->toBe('');
});

test('getRun rejects a response without a known status', function () {
    expect(fn () => makeClient([jsonResponse(200, ['data' => ['id' => 'r', 'status' => 'WEIRD']])])->getRun('r'))
        ->toThrow(ApiException::class, 'unknown run status');
});

test('wait polls a started run until it succeeds', function () {
    $history = [];
    $client = makeClient([runResponse('RUNNING', 'run5'), runResponse('SUCCEEDED', 'run5')], $history);
    $started = new Run('run5', RunStatus::Ready);

    $run = $client->wait($started);

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->defaultDatasetId)->toBe('ds1')
        ->and($history)->toHaveCount(2)
        ->and($history[0]['request']->getUri()->getPath())->toBe('/v2/actor-runs/run5')
        ->and($history[0]['request']->getUri()->getQuery())->toStartWith('waitForFinish=');
});

test('wait accepts a run id and returns a finished run without polling', function () {
    $history = [];
    $client = makeClient([runResponse('SUCCEEDED', 'run5')], $history);

    expect($client->wait('run5')->isSuccessful())->toBeTrue()
        ->and($history)->toHaveCount(1)
        ->and($history[0]['request']->getUri()->getQuery())->toBe('');

    $history = [];
    $done = makeClient([], $history)->wait(new Run('run5', RunStatus::Succeeded, 'ds1'));

    expect($done->id)->toBe('run5')
        ->and($history)->toBe([]);
});

test('wait keeps polling while a run is aborting or timing out', function () {
    $client = makeClient([runResponse('TIMING-OUT', 'r'), runResponse('ABORTING', 'r'), runResponse('ABORTED', 'r')]);

    expect(fn () => $client->wait(new Run('r', RunStatus::Running)))
        ->toThrow(ApiException::class, 'status ABORTED');
});

test('wait honours its own timeout and the exception carries the run id', function () {
    $client = makeClient([], config: new Config(timeout: 600));

    try {
        $client->wait(new Run('run6', RunStatus::Running), timeout: 0);
        $this->fail('Expected an ApiException');
    } catch (ApiException $e) {
        expect($e->getMessage())->toContain('did not finish within 0 seconds')
            ->and($e->runId)->toBe('run6');
    }
});

test('wait throws on a failed run and names the run id', function (string $status) {
    $client = makeClient([runResponse($status, 'run8')]);

    try {
        $client->wait('run8');
        $this->fail('Expected an ApiException');
    } catch (ApiException $e) {
        expect($e->getMessage())->toBe("Actor run ended with status {$status}")
            ->and($e->runId)->toBe('run8');
    }
})->with(['FAILED', 'ABORTED', 'TIMED-OUT']);

test('results reads the dataset of a successful run', function () {
    $history = [];
    $client = makeClient([jsonResponse(200, [loadFixture('transcript_full')])], $history);

    $transcripts = $client->results(new Run('run1', RunStatus::Succeeded, 'ds1'));

    expect($transcripts)->toHaveCount(1)
        ->and($transcripts[0]->isSuccessful())->toBeTrue()
        ->and($history)->toHaveCount(1)
        ->and($history[0]['request']->getUri()->getPath())->toBe('/v2/datasets/ds1/items');
});

test('results accepts a run id and looks the run up first', function () {
    $history = [];
    $client = makeClient([runResponse('SUCCEEDED', 'run1', 'dsX'), jsonResponse(200, [])], $history);

    expect($client->results('run1'))->toBe([])
        ->and($history[0]['request']->getUri()->getPath())->toBe('/v2/actor-runs/run1')
        ->and($history[1]['request']->getUri()->getPath())->toBe('/v2/datasets/dsX/items');
});

test('results refuses a run that has not succeeded', function (RunStatus $status) {
    $history = [];
    $client = makeClient([], $history);

    try {
        $client->results(new Run('run3', $status, 'ds1'));
        $this->fail('Expected an ApiException');
    } catch (ApiException $e) {
        expect($e->getMessage())->toContain("status {$status->value}")->toContain('wait()')
            ->and($e->runId)->toBe('run3')
            ->and($history)->toBe([]);
    }
})->with([RunStatus::Ready, RunStatus::Running, RunStatus::Failed, RunStatus::Aborted]);

test('results needs the dataset id and names the item index and run id of a bad item', function () {
    expect(fn () => makeClient([])->results(new Run('run3', RunStatus::Succeeded)))
        ->toThrow(ApiException::class, 'missing dataset ID');

    try {
        makeClient([jsonResponse(200, ['nope'])])->results(new Run('run3', RunStatus::Succeeded, 'ds1'));
        $this->fail('Expected an ApiException');
    } catch (ApiException $e) {
        expect($e->getMessage())->toContain('index 0')
            ->and($e->runId)->toBe('run3');
    }
});

test('abort posts to the run and returns its new state', function () {
    $history = [];
    $client = makeClient([runResponse('ABORTING', 'run4')], $history);

    $run = $client->abort(new Run('run4', RunStatus::Running));

    expect($run->status)->toBe(RunStatus::Aborting)
        ->and($history[0]['request']->getMethod())->toBe('POST')
        ->and($history[0]['request']->getUri()->getPath())->toBe('/v2/actor-runs/run4/abort');

    $history = [];
    makeClient([runResponse('ABORTED', 'run4')], $history)->abort('run4');

    expect($history[0]['request']->getUri()->getPath())->toBe('/v2/actor-runs/run4/abort');
});

test('start, wait and results work in separate steps like transcribe', function () {
    $history = [];
    $client = makeClient([
        runResponse('READY', 'run2'),
        runResponse('SUCCEEDED', 'run2'),
        jsonResponse(200, [loadFixture('transcript_full')]),
    ], $history);

    $run = $client->start(['https://example.com/a.mp3']);
    $transcripts = $client->results($client->wait($run));

    expect($transcripts)->toHaveCount(1)
        ->and(array_map(static fn (array $h): string => $h['request']->getMethod(), $history))
        ->toBe(['POST', 'GET', 'GET']);
});

test('a run start is still not repeated after a 5xx', function () {
    $history = [];
    $client = makeClient([jsonResponse(500, ['error' => 'x']), runResponse()], $history, new Config(maxRetries: 3));

    expect(fn () => $client->start(['https://example.com/a.mp3']))->toThrow(ApiException::class)
        ->and($history)->toHaveCount(1);
});

test('abort is not repeated after a 5xx either', function () {
    $history = [];
    $client = makeClient([jsonResponse(500, ['error' => 'x']), runResponse()], $history, new Config(maxRetries: 3));

    expect(fn () => $client->abort('run4'))->toThrow(ApiException::class)
        ->and($history)->toHaveCount(1);
});
