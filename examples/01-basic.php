<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use AudioTranscription\Client;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = (new Client)->setApifyKey($apiToken);

// Public domain recording: Neil Armstrong, Apollo 11 (NASA, via Wikimedia Commons)
$transcripts = $client->transcribe(['https://upload.wikimedia.org/wikipedia/commons/d/dd/Armstrong_Small_Step.ogg']);

foreach ($transcripts as $transcript) {
    echo "{$transcript->url}\n";
    echo "Language: {$transcript->language}, minutes billed: {$transcript->minutesTranscribed}\n";
    echo ($transcript->text ?? $transcript->error)."\n";
}
