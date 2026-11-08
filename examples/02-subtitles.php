<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use AudioTranscription\Client;
use AudioTranscription\Language;
use AudioTranscription\SubtitleFormat;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = new Client($apiToken);

$transcripts = $client->transcribe(
    urls: ['https://upload.wikimedia.org/wikipedia/commons/d/dd/Armstrong_Small_Step.ogg'],
    language: Language::English,
    subtitleFormats: [SubtitleFormat::Srt, SubtitleFormat::Vtt],
);

$outputDir = sys_get_temp_dir().'/audio-transcription-examples';
is_dir($outputDir) || mkdir($outputDir, 0777, true);

foreach ($transcripts as $index => $transcript) {
    if ($transcript->isFailed()) {
        echo "Failed: {$transcript->error}\n";

        continue;
    }

    file_put_contents("{$outputDir}/transcript-{$index}.srt", $transcript->srt);
    file_put_contents("{$outputDir}/transcript-{$index}.vtt", $transcript->vtt);
    echo "Saved subtitles to {$outputDir}\n";
}
