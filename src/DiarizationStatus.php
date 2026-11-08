<?php

declare(strict_types=1);

namespace AudioTranscription;

/** Outcome of speaker labelling for one file. */
enum DiarizationStatus: string
{
    case Ok = 'ok';
    case SkippedTime = 'skipped_time';
    case Failed = 'failed';
}
