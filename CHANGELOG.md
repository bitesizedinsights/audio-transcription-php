# Changelog

All notable changes to this project are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed

- Breaking: the Apify API key is no longer a `Client` constructor argument or a `Config` property. Use `(new Client())->setApifyKey($apiToken)`. Without it the client reads `APIFY_API_TOKEN` or `APIFY_TOKEN` from the environment.

### Added

- Automatic retrying with exponential backoff and jitter (`Config::$maxRetries`, `$retryBaseDelay`, `$retryMaxDelay`): rate limits honour `Retry-After`, 5xx and connection failures are retried, and starting a run is not repeated after a 5xx or timeout.
- Convenience helpers: `Segment::getTimestamp()`, `Transcript::getSegmentUrl()`, `Transcript::getTimestampedText()`, `Transcript::saveSrt()` and `Transcript::saveVtt()`.
- `toArray()` and `JsonSerializable` on `Transcript`, `Segment`, `Summary`, `Tags` and `SentimentResult`, in the Actor's field names, so transcripts can be stored and restored with `fromArray()`.
- `Segment::$speaker`: the speaker label of a segment when `diarize` is on, otherwise `null`.
- `Client::setApifyKey()` and `MissingApiKeyException`, thrown on the first API call when no key is available.

## [0.1.0] - 2026-11-08

### Added

- `Client::transcribe()` for audio and video transcription with language, translation, subtitle, speaker label and analysis options.
- Typed DTOs `Transcript`, `Segment`, `Summary`, `Tags` and `SentimentResult`.
- Enums `Language`, `SubtitleFormat`, `TranscriptStatus`, `DiarizationStatus`, `ErrorCode` and `Sentiment`.
- `ApiException` and `RateLimitException`.
- Injectable Guzzle client for testing.
