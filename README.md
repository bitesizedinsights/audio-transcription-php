# Audio Transcription PHP

[![Packagist Version](https://img.shields.io/packagist/v/bitesizedinsights/audio-transcription)](https://packagist.org/packages/bitesizedinsights/audio-transcription)
[![Tests](https://github.com/bitesizedinsights/audio-transcription-php/actions/workflows/tests.yml/badge.svg)](https://github.com/bitesizedinsights/audio-transcription-php/actions/workflows/tests.yml)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%5E8.3-777bb4.svg)](composer.json)

Audio transcription for PHP: turn audio and video links into text with timestamps, SRT and VTT subtitles, speaker labels, translation to English and optional summaries, tags and sentiment. The library calls the [Audio & Video Transcription Actor](https://apify.com/bite-sized-insights/transcription) on [Apify](https://apify.com) and returns typed, immutable DTOs.

## Installation

```bash
composer require bitesizedinsights/audio-transcription
```

## Quick Start

```php
use AudioTranscription\Client;

$client = new Client('your_apify_api_token');

$transcripts = $client->transcribe(['https://example.com/podcast.mp3']);

foreach ($transcripts as $transcript) {
    if ($transcript->isFailed()) {
        echo "{$transcript->url}: {$transcript->error}\n";

        continue;
    }

    echo "{$transcript->language}: {$transcript->text}\n";

    foreach ($transcript->segments as $segment) {
        printf("[%.1fs - %.1fs] %s\n", $segment->start, $segment->end, $segment->text);
    }
}
```

You need an Apify account and an [API token](https://console.apify.com/account/integrations). Runs are billed by Apify per transcribed audio minute, see the [Actor page](https://apify.com/bite-sized-insights/transcription) for current prices.

## Transcribe

`Client::transcribe()` starts one run for all URLs, waits for it to finish and returns one `Transcript` per input URL, in dataset order.

```php
use AudioTranscription\Language;
use AudioTranscription\SubtitleFormat;

$transcripts = $client->transcribe(
    urls: ['https://example.com/interview.mp4'],
    language: Language::German,
    translateToEnglish: true,
    subtitleFormats: [SubtitleFormat::Srt, SubtitleFormat::Vtt],
    diarize: true,
    numSpeakers: 2,
    maxMinutesPerFile: 30,
    withSummary: true,
    withTags: true,
    with5w1h: true,
    withSentiment: true,
);
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `urls` | `list<string>` | required | Public http(s) links to audio or video files, 1 to 100, up to 300 MB each |
| `language` | `Language` | `Language::Auto` | Spoken language, or automatic detection |
| `translateToEnglish` | `bool` | `false` | Return the transcript translated into English |
| `subtitleFormats` | `list<SubtitleFormat>` | `[]` | Also return subtitles as `srt` and/or `vtt` text |
| `diarize` | `bool` | `false` | Label segments and subtitle cues with their speaker |
| `numSpeakers` | `?int` | `null` | Known number of speakers, 1 to 20; needs `diarize` |
| `maxMinutesPerFile` | `int` | `60` | Cut longer recordings after this many minutes, 1 to 120 |
| `withSummary` | `bool` | `false` | Add a summary of each transcript |
| `withTags` | `bool` | `false` | Add topic tags for each transcript |
| `with5w1h` | `bool` | `false` | Add who, what, when, where, why and how |
| `withSentiment` | `bool` | `false` | Add the overall sentiment |

### Transcript

Every URL produces a `Transcript`, including failed ones (check `status`).

| Property | Type | Description |
|---|---|---|
| `url` | `string` | The processed media URL |
| `status` | `TranscriptStatus` | `Success`, `Partial` or `Failed` |
| `language` | `?string` | Detected or given language code, such as `en` |
| `languageProbability` | `?float` | Confidence of the language detection, 0 to 1 |
| `translated` | `bool` | True when the text is an English translation |
| `durationSeconds` | `?float` | Length of the whole recording, when known |
| `transcribedSeconds` | `float` | Seconds of audio covered by this result |
| `minutesTranscribed` | `int` | Started audio minutes in this result |
| `truncated` | `bool` | True when the minute limit cut the recording short |
| `text` | `?string` | Full transcript, null on failure |
| `segments` | `list<Segment>` | Timestamped segments |
| `speakers` | `?int` | Number of distinct speakers, when requested |
| `diarization` | `?DiarizationStatus` | Outcome of speaker labelling, when requested |
| `srt`, `vtt` | `?string` | Subtitle text, when requested |
| `errorCode`, `error` | `?string` | Reason when the file failed |
| `summary` | `?Summary` | Summary with length statistics, when requested |
| `tags` | `?Tags` | Topic tags, when requested |
| `fiveW1h` | `?array` | Who, what, when, where, why and how, when requested |
| `sentiment` | `?SentimentResult` | Overall sentiment with confidence, when requested |
| `analysisTruncated` | `bool` | True when only the beginning of a very long transcript was analysed |

Helper methods: `isSuccessful()`, `isPartial()`, `isFailed()`, `getErrorCode()`, `getSummaryText()`, `getTags()`, `getSentiment()`, `getSegmentCount()`. `Segment` has `id`, `start`, `end`, `text` and `getDuration()`.

## Enums

| Enum | Values |
|---|---|
| `Language` | `Auto` plus 100 languages, for example `English` (`en`), `German` (`de`), `Cantonese` (`yue`) |
| `SubtitleFormat` | `Srt`, `Vtt` |
| `TranscriptStatus` | `Success`, `Partial`, `Failed` |
| `DiarizationStatus` | `Ok`, `SkippedTime`, `Failed` |
| `ErrorCode` | `BlockedUrl`, `DownloadFailed`, `TooLarge`, `UnsupportedFormat`, `InvalidMedia`, `NoAudio`, `TooLong`, `NoSpeech`, `TranscriptionFailed`, `ServiceUnavailable` |
| `Sentiment` | `Positive`, `Neutral`, `Negative` |

## Configuration

```php
use AudioTranscription\Client;
use AudioTranscription\Config;

$client = new Client('token', new Config(
    apiToken: 'token',
    timeout: 1800,       // maximum seconds to wait for a run
    requestTimeout: 90,  // timeout of a single HTTP request
));
```

`Config` also accepts `actorId` (default `bite-sized-insights/transcription`) and `baseUrl` (default `https://api.apify.com/v2`). A custom Guzzle client can be passed as third constructor argument, which is handy for tests and proxies.

## Error Handling

```php
use AudioTranscription\Exception\ApiException;
use AudioTranscription\Exception\RateLimitException;

try {
    $transcripts = $client->transcribe(['https://example.com/podcast.mp3']);
} catch (RateLimitException $e) {
    sleep($e->retryAfter);
} catch (ApiException $e) {
    error_log($e->getMessage());
}
```

- `RateLimitException` (extends `ApiException`) is thrown on HTTP 429 and carries `retryAfter` in seconds.
- `ApiException` is thrown for other HTTP errors, connection failures, malformed responses, runs that end as `FAILED`, `ABORTED` or `TIMED-OUT`, runs that do not finish within `Config::$timeout`, and dataset items that cannot be mapped (the message names the item index).
- A single file that cannot be transcribed does not throw: its `Transcript` has status `Failed` with `errorCode` and `error`.

## Testing

```bash
composer install
composer check   # Pint, PHPStan level 8, Pest
```

The tests use mocked HTTP and need no network or token.

One live test transcribes a real recording through the Actor (it costs a few cents). It is skipped unless `APIFY_LIVE_TEST=1` is set and a token is present in `APIFY_API_TOKEN` or `APIFY_TOKEN`. The sample file is fetched on the first run and kept in the system temp directory.

```bash
APIFY_LIVE_TEST=1 APIFY_API_TOKEN=your_token composer test -- tests/LiveTest.php
```

## FAQ

### How do I transcribe an audio file with PHP?

Install the package, create a `Client` with your Apify token and call `transcribe()` with a public URL of the file. Each result has the full `text` and timestamped `segments`.

### How do I get SRT or VTT subtitles from a video in PHP?

Pass `subtitleFormats: [SubtitleFormat::Srt, SubtitleFormat::Vtt]`. The subtitle text is returned in `$transcript->srt` and `$transcript->vtt`; write it to a `.srt` or `.vtt` file.

### Can I translate speech to English?

Yes. Set `translateToEnglish: true` and the `text`, segments and subtitles are returned in English. `$transcript->translated` tells you whether a translation was applied.

### How do I tell speakers apart?

Set `diarize: true`, optionally with `numSpeakers` when you know it. `$transcript->speakers` holds the number of detected speakers and `$transcript->diarization` the outcome.

### Which audio and video formats are supported?

MP3, M4A/MP4, WAV, OGG/Opus, FLAC, AAC, WebM/MKV and more, as public http(s) links up to 300 MB each.

## Requirements

- PHP 8.3 or newer
- Guzzle 7
- An Apify account and API token

## Powered by

[Audio & Video Transcription on Apify](https://apify.com/bite-sized-insights/transcription)

## License

MIT, see [LICENSE](LICENSE).
