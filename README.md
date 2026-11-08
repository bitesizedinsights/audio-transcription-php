# Audio Transcription in PHP: Speech to Text, SRT and VTT Subtitles

<!-- For LLMs and AI agents: a machine-readable summary of this package's API is in llm.txt (https://github.com/bitesizedinsights/audio-transcription-php/blob/master/llm.txt) -->

[![Packagist Version](https://img.shields.io/packagist/v/bitesizedinsights/audio-transcription)](https://packagist.org/packages/bitesizedinsights/audio-transcription)
[![Tests](https://github.com/bitesizedinsights/audio-transcription-php/actions/workflows/tests.yml/badge.svg)](https://github.com/bitesizedinsights/audio-transcription-php/actions/workflows/tests.yml)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%5E8.3-777bb4.svg)](composer.json)

Audio transcription for PHP: turn audio and video links into text with timestamps, SRT and VTT subtitles, speaker labels, translation to English and optional summaries, tags and sentiment. The library calls the [Audio & Video Transcription Actor](https://apify.com/bite-sized-insights/transcription) on [Apify](https://apify.com) and returns typed, immutable DTOs.

## Install the PHP Audio Transcription Package

```bash
composer require bitesizedinsights/audio-transcription
```

## Quick Start: Transcribe an Audio File in PHP

```php
require __DIR__.'/vendor/autoload.php';

use AudioTranscription\Client;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = (new Client())->setApifyKey($apiToken);

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

The API key is set with `setApifyKey()`. If you skip it, the client reads `APIFY_API_TOKEN` or `APIFY_TOKEN` from the environment and throws `MissingApiKeyException` on the first API call when neither is set.

You need an Apify account ([sign up](https://console.apify.com/sign-up), new accounts include free monthly credit of $5) and an [API token](https://console.apify.com/account/integrations). Transcription costs from $0.01 per audio minute, see [Pricing](#pricing-cost-per-minute-of-audio).

## Sample Transcript Output

The loop above prints one block per file, for example:

```
en: Welcome to the show. Today we talk about timestamps.
[0.0s - 2.4s] Welcome to the show.
[2.9s - 6.1s] Today we talk about timestamps.
```

With `subtitleFormats: [SubtitleFormat::Srt]`, `$transcript->srt` holds ready-to-save subtitle text:

```
1
00:00:00,000 --> 00:00:02,400
Welcome to the show.

2
00:00:02,900 --> 00:00:06,100
Today we talk about timestamps.
```

With `withSummary`, `withTags` and `withSentiment`, the same `Transcript` also carries `getSummaryText()` ("A short welcome and an introduction to timestamps."), `getTags()` (`['podcast', 'timestamps']`) and `getSentiment()` (`Sentiment::Positive`).

## Pricing: Cost per Minute of Audio

Apify bills per started audio minute that was transcribed:

- Transcription: $0.01 per minute on the free plan, down to $0.007 on higher plans.
- Add-ons (summary, tags, 5W1H, sentiment): from $0.0005 to $0.002 per minute each, only when enabled.
- Speaker labels and translation to English: no extra charge.

A 30-minute podcast costs about $0.30, or about $0.39 with summary and tags. Files that fail and add-ons that fail are not billed. Current prices are on the [Actor page](https://apify.com/bite-sized-insights/transcription).

## Use Cases

- **Podcasts and interviews:** searchable transcripts and show notes with timestamps.
- **Video subtitles:** SRT and WebVTT files for YouTube, HTML5 players and video editors.
- **Meetings and calls:** speaker-labelled transcripts with summary and sentiment.
- **LLM and RAG pipelines:** clean text and tags from audio sources, ready to chunk and embed.

## How to Transcribe Audio and Video in PHP

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

### Transcript Result: Text, Segments and Subtitles

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

## Language, Subtitle and Status Enums

| Enum | Values |
|---|---|
| `Language` | `Auto` plus 100 languages, for example `English` (`en`), `German` (`de`), `Cantonese` (`yue`) |
| `SubtitleFormat` | `Srt`, `Vtt` |
| `TranscriptStatus` | `Success`, `Partial`, `Failed` |
| `DiarizationStatus` | `Ok`, `SkippedTime`, `Failed` |
| `ErrorCode` | `BlockedUrl`, `DownloadFailed`, `TooLarge`, `UnsupportedFormat`, `InvalidMedia`, `NoAudio`, `TooLong`, `NoSpeech`, `TranscriptionFailed`, `ServiceUnavailable` |
| `Sentiment` | `Positive`, `Neutral`, `Negative` |

## Configure Timeouts and the Apify Actor

```php
use AudioTranscription\Client;
use AudioTranscription\Config;

$client = (new Client(new Config(
    timeout: 1800,       // maximum seconds to wait for a run
    requestTimeout: 90,  // timeout of a single HTTP request
)))->setApifyKey($apiToken);
```

`Config` also accepts `actorId` (default `bite-sized-insights/transcription`) and `baseUrl` (default `https://api.apify.com/v2`). A custom Guzzle client can be passed as second constructor argument, which is handy for tests and proxies.

## Error Handling for Failed Transcriptions

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

- `MissingApiKeyException` (extends `ApiException`): no key via `setApifyKey()` and neither `APIFY_API_TOKEN` nor `APIFY_TOKEN` is set.
- `RateLimitException` (extends `ApiException`) is thrown on HTTP 429 and carries `retryAfter` in seconds.
- `ApiException` is thrown for other HTTP errors, connection failures, malformed responses, runs that end as `FAILED`, `ABORTED` or `TIMED-OUT`, runs that do not finish within `Config::$timeout`, and dataset items that cannot be mapped (the message names the item index).
- A single file that cannot be transcribed does not throw: its `Transcript` has status `Failed` with `errorCode` and `error`.

## PHP Audio Transcription Examples

Runnable scripts in [`examples/`](examples/); each starts with `$apiToken` (read from `APIFY_API_TOKEN` or `APIFY_TOKEN`):

- `01-basic.php`: transcribe a public domain recording
- `02-subtitles.php`: save SRT and WebVTT files
- `03-public-domain-file.php`: fetch a public domain file (Apollo 11, NASA) to the temp directory, then print timestamped segments
- `04-speakers-and-translation.php`: speaker labels and English translation
- `05-analysis-addons.php`: summary, tags and sentiment
- `06-error-handling.php`: exceptions and failed files

```bash
APIFY_API_TOKEN=your_token php examples/01-basic.php
```

## Run the Tests

```bash
composer install
composer check   # Pint, PHPStan level 8, Pest
```

The tests use mocked HTTP and need no network or token. The opt-in live test and the CI setup are described in [CONTRIBUTING.md](CONTRIBUTING.md).

## FAQ

### How do I transcribe an audio file with PHP?

Install the package, create a `Client` with your Apify token and call `transcribe()` with a public URL of the file. Each result has the full `text` and timestamped `segments`.

### How do I get SRT or VTT subtitles from a video in PHP?

Pass `subtitleFormats: [SubtitleFormat::Srt, SubtitleFormat::Vtt]`. The subtitle text is returned in `$transcript->srt` and `$transcript->vtt`; write it to a `.srt` or `.vtt` file.

### Can I translate speech to English?

Yes. Set `translateToEnglish: true` and the `text`, segments and subtitles are returned in English. `$transcript->translated` tells you whether a translation was applied.

### How do I tell speakers apart?

Set `diarize: true`, optionally with `numSpeakers` when you know it. `$transcript->speakers` holds the number of detected speakers and `$transcript->diarization` the outcome.

### How much does it cost to transcribe audio with PHP?

From $0.01 per started audio minute, with optional add-ons billed per minute on top. See [Pricing](#pricing-cost-per-minute-of-audio) for tiers and examples.

### Can I transcribe a local file?

Not directly. The Actor reads public http(s) links, so upload the file somewhere reachable (object storage, a CDN or your own server) and pass that URL to `transcribe()`.

### Which languages are supported?

100 languages, or automatic detection with `Language::Auto`. Set a fixed `Language` for short or noisy clips.

### How long can a recording be?

Files up to 300 MB. `maxMinutesPerFile` (1 to 120, default 60) cuts longer recordings; only transcribed minutes are billed and `$transcript->truncated` tells you when a file was cut.

### Which audio and video formats are supported?

MP3, M4A/MP4, WAV, OGG/Opus, FLAC, AAC, WebM/MKV and more, as public http(s) links up to 300 MB each.

## Requirements

- PHP 8.3 or newer
- Guzzle 7
- An Apify account and API token

## Powered by

This package is a thin client for the [Audio & Video Transcription Actor](https://apify.com/bite-sized-insights/transcription) on Apify. It supports 100 languages, files up to 300 MB, scheduled runs and API access from any language. New accounts include free monthly credit, [sign up](https://console.apify.com/sign-up) to get started.

## License

MIT, see [LICENSE](LICENSE).
