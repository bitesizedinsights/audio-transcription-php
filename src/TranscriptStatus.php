<?php

declare(strict_types=1);

namespace AudioTranscription;

/** Outcome of one file: `Partial` and `Skipped` ask for the same input to be run again to continue. */
enum TranscriptStatus: string
{
    case Success = 'success';
    case Partial = 'partial';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
