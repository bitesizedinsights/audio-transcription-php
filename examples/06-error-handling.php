<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use AudioTranscription\Client;
use AudioTranscription\Exception\ApiException;
use AudioTranscription\Exception\RateLimitException;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = new Client($apiToken);

try {
    $transcripts = $client->transcribe([
        'https://upload.wikimedia.org/wikipedia/commons/d/dd/Armstrong_Small_Step.ogg',
        'https://example.com/not-audio.html',
    ]);
} catch (RateLimitException $e) {
    // Wait the advised time before retrying
    sleep($e->retryAfter);

    exit("Rate limited, retry later\n");
} catch (ApiException $e) {
    exit("API error: {$e->getMessage()}\n");
}

// A single bad file does not throw: it comes back as a failed transcript
foreach ($transcripts as $transcript) {
    if ($transcript->isFailed()) {
        echo "{$transcript->url}: failed ({$transcript->errorCode}) {$transcript->error}\n";

        continue;
    }

    echo "{$transcript->url}: {$transcript->getSegmentCount()} segments\n";
}
