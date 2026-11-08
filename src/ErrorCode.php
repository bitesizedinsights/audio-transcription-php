<?php

declare(strict_types=1);

namespace AudioTranscription;

/** Short reason for a failed file. */
enum ErrorCode: string
{
    case BlockedUrl = 'blocked_url';
    case DownloadFailed = 'download_failed';
    case TooLarge = 'too_large';
    case UnsupportedFormat = 'unsupported_format';
    case InvalidMedia = 'invalid_media';
    case NoAudio = 'no_audio';
    case TooLong = 'too_long';
    case NoSpeech = 'no_speech';
    case TranscriptionFailed = 'transcription_failed';
    case ServiceUnavailable = 'service_unavailable';
}
