<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use AudioTranscription\Client;
use AudioTranscription\Language;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

// Public domain: Neil Armstrong, Apollo 11 (NASA, via Wikimedia Commons)
$sourceUrl = 'https://upload.wikimedia.org/wikipedia/commons/d/dd/Armstrong_Small_Step.ogg';

// Fetch the file once and keep it in the temp directory
$localFile = sys_get_temp_dir().'/audio-transcription-armstrong.ogg';

if (! is_file($localFile) || filesize($localFile) === 0) {
    $context = stream_context_create(['http' => ['timeout' => 60, 'user_agent' => 'audio-transcription-php-example']]);
    $data = file_get_contents($sourceUrl, false, $context);

    if ($data === false) {
        exit("Could not download {$sourceUrl}\n");
    }

    file_put_contents($localFile, $data);
}

printf("Local copy: %s (%d KB)\n", $localFile, filesize($localFile) / 1024);

// The Actor reads public URLs, so send the source URL rather than the local file
$client = (new Client)->setApifyKey($apiToken);
[$transcript] = $client->transcribe([$sourceUrl], language: Language::English);

if ($transcript->isFailed()) {
    exit("Failed ({$transcript->errorCode}): {$transcript->error}\n");
}

echo $transcript->text."\n\n";

foreach ($transcript->segments as $segment) {
    printf("[%05.1f - %05.1f] %s\n", $segment->start, $segment->end, $segment->text);
}
