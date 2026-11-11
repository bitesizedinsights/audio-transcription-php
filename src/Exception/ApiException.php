<?php

declare(strict_types=1);

namespace AudioTranscription\Exception;

use Exception;
use Throwable;

class ApiException extends Exception
{
    /** @param  string|null  $runId  The Apify run the error belongs to, when known: use it to look the run up again. */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly ?string $runId = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
