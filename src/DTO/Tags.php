<?php

declare(strict_types=1);

namespace AudioTranscription\DTO;

use AudioTranscription\Support\Value;
use InvalidArgumentException;
use JsonSerializable;

final readonly class Tags implements JsonSerializable
{
    /** @param  list<string>  $tags */
    public function __construct(
        public array $tags,
        public int $count,
    ) {}

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): self
    {
        $tags = [];

        foreach (Value::array($data, 'tags') as $tag) {
            if (! is_string($tag)) {
                throw new InvalidArgumentException("Field 'tags' must contain strings");
            }

            $tags[] = $tag;
        }

        return new self($tags, Value::nullableInt($data, 'count') ?? count($tags));
    }

    /**
     * The Actor's field names, so the result can be passed back to `fromArray()`.
     *
     * @return array{tags: list<string>, count: int}
     */
    public function toArray(): array
    {
        return ['tags' => $this->tags, 'count' => $this->count];
    }

    /** @return array{tags: list<string>, count: int} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
