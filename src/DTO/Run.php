<?php

declare(strict_types=1);

namespace AudioTranscription\DTO;

use AudioTranscription\RunStatus;
use AudioTranscription\Support\Value;
use InvalidArgumentException;
use JsonSerializable;

/** Handle of an Apify run: keep `$id` to look the run up or collect its results later. */
final readonly class Run implements JsonSerializable
{
    public function __construct(
        public string $id,
        public RunStatus $status,
        public ?string $defaultDatasetId = null,
        public ?string $startedAt = null,
        public ?string $finishedAt = null,
    ) {}

    /**
     * @param  array<mixed>  $data
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        $status = Value::string($data, 'status');

        return new self(
            id: Value::string($data, 'id'),
            status: RunStatus::tryFrom($status)
                ?? throw new InvalidArgumentException("Field 'status' has the unknown value '{$status}'"),
            defaultDatasetId: Value::nullableString($data, 'defaultDatasetId'),
            startedAt: Value::nullableString($data, 'startedAt'),
            finishedAt: Value::nullableString($data, 'finishedAt'),
        );
    }

    public function isFinished(): bool
    {
        return $this->status->isFinished();
    }

    public function isSuccessful(): bool
    {
        return $this->status === RunStatus::Succeeded;
    }

    /**
     * Apify's field names, so the result can be passed back to `fromArray()`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'defaultDatasetId' => $this->defaultDatasetId,
            'startedAt' => $this->startedAt,
            'finishedAt' => $this->finishedAt,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
