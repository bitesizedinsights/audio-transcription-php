<?php

declare(strict_types=1);

namespace AudioTranscription\Exception;

class MissingApiKeyException extends ApiException
{
    public function __construct(
        string $message = 'No Apify API key set. Call setApifyKey() or set APIFY_API_TOKEN (or APIFY_TOKEN).',
    ) {
        parent::__construct($message);
    }
}
