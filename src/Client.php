<?php

declare(strict_types=1);

namespace AudioTranscription;

use AudioTranscription\DTO\Run;
use AudioTranscription\DTO\Transcript;
use AudioTranscription\Exception\ApiException;
use AudioTranscription\Exception\MissingApiKeyException;
use AudioTranscription\Exception\RateLimitException;
use Closure;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use JsonException;

final class Client
{
    /** Longest wait Apify allows per request. */
    private const MAX_WAIT_SECONDS = 60;

    private readonly Config $config;

    private readonly ClientInterface $http;

    private ?string $apiKey = null;

    /** @var Closure(float): void */
    private readonly Closure $sleeper;

    /**
     * @param  (Closure(float): void)|null  $sleeper  Waits the given seconds between retries; replaced in tests.
     */
    public function __construct(?Config $config = null, ?ClientInterface $http = null, ?Closure $sleeper = null)
    {
        $this->config = $config ?? new Config;
        $this->http = $http ?? new HttpClient;
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    /**
     * Set the Apify API key. Without it, `APIFY_API_TOKEN` or `APIFY_TOKEN` from the environment is used.
     */
    public function setApifyKey(string $apiKey): static
    {
        $this->apiKey = $apiKey;

        return $this;
    }

    /**
     * Transcribe audio or video files and return one result per URL.
     *
     * @param  list<string>  $urls  Public http(s) links, up to 100, up to 300 MB each. Needed unless `$feedUrls` is given.
     * @param  Language  $language  Spoken language; `Language::Auto` detects it.
     * @param  bool  $translateToEnglish  Return the transcript translated into English.
     * @param  list<SubtitleFormat>  $subtitleFormats  Also return subtitles in these formats.
     * @param  bool  $diarize  Label segments and subtitle cues with their speaker.
     * @param  int|null  $numSpeakers  Known number of speakers, 1 to 20; needs `$diarize`.
     * @param  int  $maxMinutesPerFile  Cut longer recordings after this many minutes, 1 to 120.
     * @param  bool  $withSummary  Add a summary of each transcript.
     * @param  bool  $withTags  Add topic tags for each transcript.
     * @param  bool  $with5w1h  Add who, what, when, where, why and how of each transcript.
     * @param  bool  $withSentiment  Add the overall sentiment of each transcript.
     * @param  list<string>  $feedUrls  RSS or Atom feeds of podcasts, up to 20: their newest episodes are transcribed too.
     * @param  int  $maxEpisodesPerFeed  Newest episodes per feed, 1 to 50. Links and episodes make at most 100 files.
     * @return list<Transcript> One per file (and one failed row per feed that could not be read), links first.
     *
     * @throws InvalidArgumentException when neither `$urls` nor `$feedUrls` is given, or `$maxEpisodesPerFeed` is
     *                                  out of range
     * @throws ApiException
     * @throws RateLimitException
     */
    public function transcribe(
        array $urls = [],
        Language $language = Language::Auto,
        bool $translateToEnglish = false,
        array $subtitleFormats = [],
        bool $diarize = false,
        ?int $numSpeakers = null,
        int $maxMinutesPerFile = 60,
        bool $withSummary = false,
        bool $withTags = false,
        bool $with5w1h = false,
        bool $withSentiment = false,
        array $feedUrls = [],
        int $maxEpisodesPerFeed = 5,
    ): array {
        $run = $this->start(
            $urls,
            $language,
            $translateToEnglish,
            $subtitleFormats,
            $diarize,
            $numSpeakers,
            $maxMinutesPerFile,
            $withSummary,
            $withTags,
            $with5w1h,
            $withSentiment,
            $feedUrls,
            $maxEpisodesPerFeed,
        );

        return $this->results($this->wait($run));
    }

    /**
     * Start a transcription run and return at once; the run keeps going on Apify. Take the arguments of
     * `transcribe()`. Keep `$run->id`, then collect the transcripts with `wait()` and `results()`, in this or a
     * later process.
     *
     * @param  list<string>  $urls
     * @param  list<SubtitleFormat>  $subtitleFormats
     * @param  list<string>  $feedUrls
     *
     * @throws InvalidArgumentException when neither `$urls` nor `$feedUrls` is given, or `$maxEpisodesPerFeed` is
     *                                  out of range
     * @throws ApiException
     * @throws RateLimitException
     */
    public function start(
        array $urls = [],
        Language $language = Language::Auto,
        bool $translateToEnglish = false,
        array $subtitleFormats = [],
        bool $diarize = false,
        ?int $numSpeakers = null,
        int $maxMinutesPerFile = 60,
        bool $withSummary = false,
        bool $withTags = false,
        bool $with5w1h = false,
        bool $withSentiment = false,
        array $feedUrls = [],
        int $maxEpisodesPerFeed = 5,
    ): Run {
        $input = $this->buildInput(
            $urls,
            $language,
            $translateToEnglish,
            $subtitleFormats,
            $diarize,
            $numSpeakers,
            $maxMinutesPerFile,
            $withSummary,
            $withTags,
            $with5w1h,
            $withSentiment,
            $feedUrls,
            $maxEpisodesPerFeed,
        );

        $actor = str_replace('/', '~', $this->config->actorId);
        $options = ['json' => $input];

        if ($this->config->runTimeout !== null) {
            $options['query'] = ['timeout' => $this->config->runTimeout];
        }

        return $this->parseRun($this->request('POST', "/acts/{$actor}/runs", $options));
    }

    /**
     * Look a run up once, without waiting for it.
     *
     * @throws ApiException
     * @throws RateLimitException
     */
    public function getRun(string $runId): Run
    {
        return $this->fetchRun($runId);
    }

    /**
     * Wait until the run has finished successfully. Accepts the run from `start()` or just its ID.
     *
     * @param  int|null  $timeout  Seconds to wait at most; defaults to `Config::$timeout`.
     *
     * @throws ApiException when the run ends as FAILED, ABORTED or TIMED-OUT, or does not finish in time
     * @throws RateLimitException
     */
    public function wait(Run|string $run, ?int $timeout = null): Run
    {
        $run = is_string($run) ? $this->fetchRun($run) : $run;
        $limit = $timeout ?? $this->config->timeout;
        $deadline = time() + $limit;

        while (true) {
            if ($run->isSuccessful()) {
                return $run;
            }

            if ($run->status->isFailure()) {
                throw new ApiException("Actor run ended with status {$run->status->value}", 0, null, $run->id);
            }

            $remaining = $deadline - time();

            if ($remaining <= 0) {
                throw new ApiException("Actor run did not finish within {$limit} seconds", 0, null, $run->id);
            }

            $run = $this->fetchRun($run->id, min(self::MAX_WAIT_SECONDS, $remaining));
        }
    }

    /**
     * Read the transcripts of a successful run: one per file in dataset order, like `transcribe()`. Accepts the
     * run or just its ID.
     *
     * @return list<Transcript>
     *
     * @throws ApiException when the run has not succeeded (call `wait()` first) or the dataset cannot be mapped
     * @throws RateLimitException
     */
    public function results(Run|string $run): array
    {
        $run = is_string($run) ? $this->fetchRun($run) : $run;

        if (! $run->isSuccessful()) {
            throw new ApiException(
                "Run {$run->id} has status {$run->status->value}; results exist only after SUCCEEDED, call wait()",
                0,
                null,
                $run->id,
            );
        }

        if ($run->defaultDatasetId === null || $run->defaultDatasetId === '') {
            throw new ApiException('Invalid API response: missing dataset ID', 0, null, $run->id);
        }

        return $this->fetchTranscripts($run);
    }

    /**
     * Ask Apify to stop a run. Returns the run in its new state, usually ABORTING or ABORTED.
     *
     * @throws ApiException
     * @throws RateLimitException
     */
    public function abort(Run|string $run): Run
    {
        $runId = is_string($run) ? $run : $run->id;

        return $this->parseRun($this->request('POST', '/actor-runs/'.rawurlencode($runId).'/abort'));
    }

    /**
     * @param  list<string>  $urls
     * @param  list<SubtitleFormat>  $subtitleFormats
     * @param  list<string>  $feedUrls
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function buildInput(
        array $urls,
        Language $language,
        bool $translateToEnglish,
        array $subtitleFormats,
        bool $diarize,
        ?int $numSpeakers,
        int $maxMinutesPerFile,
        bool $withSummary,
        bool $withTags,
        bool $with5w1h,
        bool $withSentiment,
        array $feedUrls,
        int $maxEpisodesPerFeed,
    ): array {
        if ($urls === [] && $feedUrls === []) {
            throw new InvalidArgumentException('Pass at least one URL in $urls or one podcast feed in $feedUrls');
        }

        if ($maxEpisodesPerFeed < 1 || $maxEpisodesPerFeed > 50) {
            throw new InvalidArgumentException('$maxEpisodesPerFeed must be between 1 and 50');
        }

        $input = $urls === [] ? [] : ['urls' => $urls];
        $input += [
            'language' => $language->value,
            'maxMinutesPerFile' => $maxMinutesPerFile,
        ];

        $optional = [
            'translateToEnglish' => $translateToEnglish ?: null,
            'subtitleFormats' => $subtitleFormats === []
                ? null
                : array_map(static fn (SubtitleFormat $f): string => $f->value, $subtitleFormats),
            'diarize' => $diarize ?: null,
            'numSpeakers' => $numSpeakers,
            'withSummary' => $withSummary ?: null,
            'withTags' => $withTags ?: null,
            'with5w1h' => $with5w1h ?: null,
            'withSentiment' => $withSentiment ?: null,
            'feedUrls' => $feedUrls === [] ? null : $feedUrls,
            'maxEpisodesPerFeed' => $feedUrls === [] ? null : $maxEpisodesPerFeed,
        ];

        foreach ($optional as $key => $value) {
            if ($value !== null) {
                $input[$key] = $value;
            }
        }

        return $input;
    }

    /**
     * @param  int|null  $waitForFinish  Let Apify hold the request open for up to this many seconds.
     */
    private function fetchRun(string $runId, ?int $waitForFinish = null): Run
    {
        $options = $waitForFinish === null ? [] : ['query' => ['waitForFinish' => $waitForFinish]];

        return $this->parseRun($this->request('GET', '/actor-runs/'.rawurlencode($runId), $options));
    }

    /**
     * @param  array<mixed>  $result
     */
    private function parseRun(array $result): Run
    {
        $data = $this->runData($result);

        if (! is_string($data['id'] ?? null) || $data['id'] === '') {
            throw new ApiException('Invalid API response: missing run ID');
        }

        $status = $data['status'] ?? null;

        if (! is_string($status) || RunStatus::tryFrom($status) === null) {
            throw new ApiException('Invalid API response: missing or unknown run status', 0, null, $data['id']);
        }

        try {
            return Run::fromArray($data);
        } catch (InvalidArgumentException $e) {
            throw new ApiException('Invalid API response: '.$e->getMessage(), 0, $e, $data['id']);
        }
    }

    /**
     * @return list<Transcript>
     */
    private function fetchTranscripts(Run $run): array
    {
        $items = $this->request('GET', "/datasets/{$run->defaultDatasetId}/items", ['query' => ['format' => 'json']]);

        $transcripts = [];

        foreach (array_values($items) as $index => $item) {
            try {
                if (! is_array($item)) {
                    throw new InvalidArgumentException('Item must be an object');
                }

                $transcripts[] = Transcript::fromArray($item);
            } catch (InvalidArgumentException $e) {
                throw new ApiException(
                    "Invalid dataset item at index {$index}: ".$e->getMessage(),
                    0,
                    $e,
                    $run->id,
                );
            }
        }

        return $transcripts;
    }

    /**
     * @param  array<mixed>  $result
     * @return array<mixed>
     */
    private function runData(array $result): array
    {
        $data = $result['data'] ?? null;

        if (! is_array($data)) {
            throw new ApiException('Invalid API response: missing run data');
        }

        return $data;
    }

    /**
     * @throws MissingApiKeyException
     */
    private function resolveApiKey(): string
    {
        $key = $this->apiKey ?: (getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN'));

        if (! is_string($key) || $key === '') {
            throw new MissingApiKeyException;
        }

        return $key;
    }

    /**
     * Sends a request and retries what is safe to repeat: rate limits (429, the request was not processed), server
     * errors (5xx) and connection failures. Starting a run (POST) is only repeated after a 429 or a failed
     * connection, because after a 5xx or a timeout the run may already exist and a repeat would bill it twice.
     *
     * @param  array<string, mixed>  $options
     * @return array<mixed>
     *
     * @throws ApiException
     * @throws RateLimitException
     */
    private function request(string $method, string $path, array $options = []): array
    {
        $options['headers'] = [
            'Authorization' => 'Bearer '.$this->resolveApiKey(),
            'Accept' => 'application/json',
        ];
        $options['timeout'] = $this->config->requestTimeout;

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->http->request($method, rtrim($this->config->baseUrl, '/').$path, $options);
                $decoded = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
            } catch (BadResponseException $e) {
                $status = $e->getResponse()->getStatusCode();
                $retryable = $status === 429 || ($status >= 500 && $method !== 'POST');
                $delay = $retryable && $attempt < $this->config->maxRetries
                    ? $this->retryDelay($attempt, $status === 429 ? $this->retryAfter($e) : null)
                    : null;

                if ($delay === null) {
                    $this->handleBadResponse($e);
                }

                ($this->sleeper)($delay);

                continue;
            } catch (JsonException $e) {
                throw new ApiException('Invalid API response: malformed JSON', 0, $e);
            } catch (GuzzleException $e) {
                // A failed connection never reached the server, so it is safe to repeat even for POST.
                $retryable = $e instanceof ConnectException || $method !== 'POST';

                if ($retryable && $attempt < $this->config->maxRetries) {
                    ($this->sleeper)($this->retryDelay($attempt, null) ?? 0.0);

                    continue;
                }

                throw new ApiException('API request failed: '.$e->getMessage(), 0, $e);
            }

            if (! is_array($decoded)) {
                throw new ApiException('Invalid API response: unexpected JSON');
            }

            return $decoded;
        }
    }

    /**
     * Seconds to wait before the next retry: exponential from the base delay with 50 to 100 percent jitter, at least
     * the server's `Retry-After`. Null when the server asks for a longer wait than the configured maximum.
     */
    private function retryDelay(int $attempt, ?int $retryAfter): ?float
    {
        if ($retryAfter !== null && $retryAfter > $this->config->retryMaxDelay) {
            return null;
        }

        $backoff = min($this->config->retryMaxDelay, $this->config->retryBaseDelay * (2 ** $attempt));
        $jittered = $backoff * (random_int(500, 1000) / 1000);

        return max($jittered, (float) ($retryAfter ?? 0));
    }

    private function retryAfter(BadResponseException $e): int
    {
        return (int) ($e->getResponse()->getHeaderLine('Retry-After') ?: 60);
    }

    private function handleBadResponse(BadResponseException $e): never
    {
        $response = $e->getResponse();

        if ($response->getStatusCode() === 429) {
            throw new RateLimitException('Rate limit exceeded', $this->retryAfter($e));
        }

        throw new ApiException(
            "API request failed with status {$response->getStatusCode()}: ".$e->getMessage(),
            $response->getStatusCode(),
            $e,
        );
    }
}
