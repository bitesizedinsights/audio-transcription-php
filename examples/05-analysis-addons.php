<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use AudioTranscription\Client;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = new Client($apiToken);

// Add-ons are billed per transcribed minute
$transcripts = $client->transcribe(
    urls: ['https://upload.wikimedia.org/wikipedia/commons/d/dd/Armstrong_Small_Step.ogg'],
    withSummary: true,
    withTags: true,
    withSentiment: true,
);

foreach ($transcripts as $transcript) {
    echo 'Summary: '.($transcript->getSummaryText() ?? 'n/a')."\n";
    echo 'Tags: '.implode(', ', $transcript->getTags())."\n";
    echo 'Sentiment: '.($transcript->getSentiment()?->value ?? 'n/a')."\n";
}
