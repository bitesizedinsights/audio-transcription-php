<?php

declare(strict_types=1);

namespace AudioTranscription;

/** Outcome of one input file. */
enum TranscriptStatus: string
{
    case Success = 'success';
    case Partial = 'partial';
    case Failed = 'failed';
}
