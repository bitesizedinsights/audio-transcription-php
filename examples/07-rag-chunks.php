<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use AudioTranscription\Client;
use AudioTranscription\Language;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = (new Client)->setApifyKey($apiToken);

$transcripts = $client->transcribe(
    urls: ['https://upload.wikimedia.org/wikipedia/commons/d/dd/Armstrong_Small_Step.ogg'],
    language: Language::English,
    diarize: true,
);

foreach ($transcripts as $transcript) {
    if ($transcript->isFailed()) {
        echo "Failed: {$transcript->error}\n";

        continue;
    }

    // Chunks of up to 60 seconds, split on speaker changes and pauses of 2 seconds, one segment of overlap.
    foreach ($transcript->chunks(maxSeconds: 60, overlapSegments: 1, pauseSeconds: 2.0) as $chunk) {
        printf(
            "#%d [%s - %.0fs] %s\n    %s\n",
            $chunk->index,
            $transcript->segments[$chunk->segmentIds[0]]->getTimestamp(),
            $chunk->end,
            $transcript->getChunkUrl($chunk),
            $chunk->text,
        );

        // Embed $chunk->text and store it with $chunk->start, $chunk->end, $chunk->speakers and the media URL.
    }
}
