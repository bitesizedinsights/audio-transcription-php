<?php

declare(strict_types=1);

namespace AudioTranscription;

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
    private const FAILED_STATUSES = ['FAILED', 'ABORTED', 'TIMED-OUT'];

    private const RUNNING_STATUSES = ['READY', 'RUNNING'];

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
     * @param  list<string>  $urls  Public http(s) links, 1 to 100, up to 300 MB each.
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
     * @return list<Transcript>
     *
     * @throws ApiException
     * @throws RateLimitException
     */
    public function transcribe(
        array $urls,
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
    ): array {
        $input = [
            'urls' => $urls,
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
        ];

        foreach ($optional as $key => $value) {
            if ($value !== null) {
                $input[$key] = $value;
            }
        }

        $run = $this->startRun($input);
        $run = $this->awaitRun($run);

        return $this->fetchTranscripts($this->datasetId($run));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<mixed>
     */
    private function startRun(array $input): array
    {
        $actor = str_replace('/', '~', $this->config->actorId);
        $result = $this->request('POST', "/acts/{$actor}/runs", ['json' => $input]);

        return $this->runData($result);
    }

    /**
     * @param  array<mixed>  $run
     * @return array<mixed>
     */
    private function awaitRun(array $run): array
    {
        $deadline = time() + $this->config->timeout;

        while (true) {
            $status = $run['status'] ?? null;

            if ($status === 'SUCCEEDED') {
                return $run;
            }

            if (is_string($status) && in_array($status, self::FAILED_STATUSES, true)) {
                throw new ApiException("Actor run ended with status {$status}");
            }

            if (! is_string($status) || ! in_array($status, self::RUNNING_STATUSES, true)) {
                throw new ApiException('Invalid API response: missing or unknown run status');
            }

            $remaining = $deadline - time();

            if ($remaining <= 0) {
                throw new ApiException("Actor run did not finish within {$this->config->timeout} seconds");
            }

            $runId = $run['id'] ?? null;

            if (! is_string($runId)) {
                throw new ApiException('Invalid API response: missing run ID');
            }

            $result = $this->request('GET', "/actor-runs/{$runId}", [
                'query' => ['waitForFinish' => min(self::MAX_WAIT_SECONDS, $remaining)],
            ]);
            $run = $this->runData($result);
        }
    }

    /**
     * @param  array<mixed>  $run
     */
    private function datasetId(array $run): string
    {
        $datasetId = $run['defaultDatasetId'] ?? null;

        if (! is_string($datasetId) || $datasetId === '') {
            throw new ApiException('Invalid API response: missing dataset ID');
        }

        return $datasetId;
    }

    /**
     * @return list<Transcript>
     */
    private function fetchTranscripts(string $datasetId): array
    {
        $items = $this->request('GET', "/datasets/{$datasetId}/items", ['query' => ['format' => 'json']]);

        $transcripts = [];

        foreach (array_values($items) as $index => $item) {
            try {
                if (! is_array($item)) {
                    throw new InvalidArgumentException('Item must be an object');
                }

                $transcripts[] = Transcript::fromArray($item);
            } catch (InvalidArgumentException $e) {
                throw new ApiException("Invalid dataset item at index {$index}: ".$e->getMessage(), 0, $e);
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
