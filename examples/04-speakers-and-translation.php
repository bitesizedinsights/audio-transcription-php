<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use AudioTranscription\Client;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = new Client($apiToken);

// Label speakers (SPEAKER_00, ...) and return an English translation of non-English speech
$transcripts = $client->transcribe(
    urls: ['https://example.com/interview-in-german.mp3'],
    translateToEnglish: true,
    diarize: true,
    numSpeakers: 2,
);

foreach ($transcripts as $transcript) {
    if ($transcript->isFailed()) {
        echo "Failed: {$transcript->error}\n";

        continue;
    }

    echo "Detected language: {$transcript->language}, speakers: {$transcript->speakers}\n";
    echo $transcript->text."\n";
}
