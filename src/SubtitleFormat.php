<?php

declare(strict_types=1);

namespace AudioTranscription;

/** Subtitle file format returned next to the transcript. */
enum SubtitleFormat: string
{
    case Srt = 'srt';
    case Vtt = 'vtt';
}
