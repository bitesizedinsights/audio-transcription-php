<?php

declare(strict_types=1);

namespace AudioTranscription;

final readonly class Config
{
    /**
     * @param  int  $timeout  Maximum seconds to wait for a run to finish.
     * @param  int|null  $runTimeout  Apify's own time limit of the run in seconds, sent as the run's `timeout`. Null =
     *                                the Actor's default (300 s), too short for podcast feeds and long recordings: a
     *                                run that reaches it skips what it could not finish (`TranscriptStatus::Skipped`).
     *                                Keep `$timeout` above it.
     * @param  int  $requestTimeout  Timeout in seconds of a single HTTP request.
     * @param  int  $maxRetries  How often a failed request is repeated (0 turns retrying off). Rate limits (429), server
     *                           errors (5xx) and connection failures are retried; see the README for the exact rules.
     * @param  float  $retryBaseDelay  Seconds before the first retry; doubles with each further retry, with jitter.
     * @param  float  $retryMaxDelay  Longest wait before a retry. A `Retry-After` above this is not waited out.
     */
    public function __construct(
        public string $actorId = 'bite-sized-insights/transcription',
        public string $baseUrl = 'https://api.apify.com/v2',
        public int $timeout = 600,
        public int $requestTimeout = 90,
        public int $maxRetries = 3,
        public float $retryBaseDelay = 1.0,
        public float $retryMaxDelay = 30.0,
        public ?int $runTimeout = null,
    ) {}
}
