<?php

declare(strict_types=1);

namespace CatFramework\Core\Model;

readonly class TermEntry
{
    public function __construct(
        /** The source-language term. */
        public string $sourceTerm,

        /** Approved target-language translation. */
        public string $targetTerm,

        public string $sourceLanguage,
        public string $targetLanguage,

        /** Optional definition or usage note. */
        public ?string $definition = null,

        /** Domain or subject area (e.g., "legal", "medical"). */
        public ?string $domain = null,

        /**
         * If true, this is a "forbidden" entry: the target term should NOT
         * be used. QA checks flag it if found in a translation.
         * Example: "click" → "tap" (not "press") in mobile UI context.
         */
        public bool $forbidden = false,
    ) {}
}
