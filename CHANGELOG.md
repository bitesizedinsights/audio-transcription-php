# Changelog

All notable changes to this project are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/).

## [0.1.0] - 2026-11-08

### Added

- `Client::transcribe()` for audio and video transcription with language, translation, subtitle, speaker label and analysis options.
- Typed DTOs `Transcript`, `Segment`, `Summary`, `Tags` and `SentimentResult`.
- Enums `Language`, `SubtitleFormat`, `TranscriptStatus`, `DiarizationStatus`, `ErrorCode` and `Sentiment`.
- `ApiException` and `RateLimitException`.
- Injectable Guzzle client for testing.
