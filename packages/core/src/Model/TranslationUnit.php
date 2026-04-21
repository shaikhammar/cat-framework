<?php

declare(strict_types=1);

namespace CatFramework\Core\Model;

readonly class TranslationUnit
{
    public function __construct(
        /**
         * Source content. Stored as a Segment (with InlineCodes) so that
         * exact matching can compare code positions, not just plain text.
         */
        public Segment $source,

        /** Target content (the translation). */
        public Segment $target,

        /** BCP 47 source language. */
        public string $sourceLanguage,

        /** BCP 47 target language. */
        public string $targetLanguage,

        /**
         * When this TU was created or imported. Used for "most recent wins"
         * conflict resolution when importing TMX files with duplicates.
         */
        public \DateTimeImmutable $createdAt,

        /**
         * Last time this TU was returned as a match result. Used for TM
         * maintenance: entries not used in years can be pruned.
         * Null = never used since import.
         */
        public ?\DateTimeImmutable $lastUsedAt = null,

        /**
         * Creator identifier (translator name, email, or system ID).
         * Imported from TMX <prop type="x-createdBy"> or set on creation.
         */
        public ?string $createdBy = null,

        /**
         * Arbitrary key-value metadata. Common keys: "project", "client",
         * "domain", "note". Maps to TMX <prop> and <note> elements.
         *
         * @var array<string, mixed>
         */
        public array $metadata = [],
    ) {}
}
