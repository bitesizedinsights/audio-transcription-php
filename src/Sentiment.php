<?php

declare(strict_types=1);

namespace AudioTranscription;

/** Overall sentiment of a transcript. */
enum Sentiment: string
{
    case Positive = 'positive';
    case Neutral = 'neutral';
    case Negative = 'negative';
}
