<?php

declare(strict_types=1);

use AudioTranscription\Client;
use AudioTranscription\Config;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * @return array<string, mixed>
 */
function loadFixture(string $name): array
{
    $json = file_get_contents(__DIR__."/fixtures/{$name}.json");
    assert(is_string($json));

    /** @var array<string, mixed> */
    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @param  array<mixed>  $body
 * @param  array<string, string>  $headers
 */
function jsonResponse(int $status, array $body, array $headers = []): Response
{
    return new Response($status, $headers, json_encode($body, JSON_THROW_ON_ERROR));
}

function runResponse(string $status = 'SUCCEEDED', string $id = 'run1', ?string $datasetId = 'ds1'): Response
{
    $data = ['id' => $id, 'status' => $status];

    if ($datasetId !== null) {
        $data['defaultDatasetId'] = $datasetId;
    }

    return jsonResponse(201, ['data' => $data]);
}

/**
 * @param  list<Response|Throwable>  $responses
 * @param  array<int, array<string, mixed>>  $history
 */
function makeClient(
    array $responses,
    array &$history = [],
    ?Config $config = null,
    ?string $apiKey = 'test-token',
    array &$sleeps = [],
): Client {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    $client = new Client(
        $config ?? new Config(maxRetries: 0),
        new HttpClient(['handler' => $stack]),
        static function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        },
    );

    return $apiKey === null ? $client : $client->setApifyKey($apiKey);
}
