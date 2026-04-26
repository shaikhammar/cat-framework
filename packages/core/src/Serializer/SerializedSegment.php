<?php

declare(strict_types=1);

namespace CatFramework\Core\Serializer;

/**
 * Result of InlineTagSerializer::serialize(). Holds the placeholder text and
 * the tag map needed to reconstruct the original InlineCode objects on deserialize.
 */
readonly class SerializedSegment
{
    /**
     * @param string  $text   Segment text with {N}, {/N}, {N/} placeholders replacing InlineCodes.
     * @param array[] $tagMap Array of tag descriptors: [id, type, data, displayText].
     */
    public function __construct(
        public string $text,
        public array $tagMap,
    ) {}
}
