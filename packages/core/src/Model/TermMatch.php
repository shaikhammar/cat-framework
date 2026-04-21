<?php

declare(strict_types=1);

namespace CatFramework\Core\Model;

readonly class TermMatch
{
    public function __construct(
        /** The matched terminology entry. */
        public TermEntry $entry,

        /** Character offset in the source text where the term starts. */
        public int $offset,

        /** Character length of the matched span. */
        public int $length,
    ) {}
}
