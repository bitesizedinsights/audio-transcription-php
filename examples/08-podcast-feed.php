<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use AudioTranscription\Client;
use AudioTranscription\Config;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

// Apify stops a run after its time limit (300 s by default): give feed runs more, and wait for them a bit longer.
$client = (new Client(new Config(timeout: 3700, runTimeout: 3600)))->setApifyKey($apiToken);

$transcripts = $client->transcribe(
    feedUrls: ['https://feeds.example.com/my-podcast.xml'],
    maxEpisodesPerFeed: 3,
);

$rerun = false;

foreach ($transcripts as $transcript) {
    if ($transcript->isFailed()) {
        echo "{$transcript->url}: {$transcript->error}\n";

        continue;
    }

    if ($transcript->needsRerun()) {
        $rerun = true;
        echo "{$transcript->episodeTitle}: {$transcript->message}\n";

        continue;
    }

    printf(
        "%s (%s): %d new + %d cached minutes\n",
        $transcript->episodeTitle,
        $transcript->getEpisodePublishedAt()?->format('Y-m-d') ?? 'undated',
        $transcript->minutesTranscribed,
        $transcript->minutesCached,
    );
}

if ($rerun) {
    echo "Run the same call again to continue: finished work is cached and not billed twice.\n";
}
