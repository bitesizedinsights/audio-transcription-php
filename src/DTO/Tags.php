<?php

declare(strict_types=1);

namespace AudioTranscription\DTO;

use AudioTranscription\Support\Value;
use InvalidArgumentException;

final readonly class Tags
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
}
