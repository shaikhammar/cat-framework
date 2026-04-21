<?php

declare(strict_types=1);

namespace CatFramework\Core\Model;

class Segment
{
    /**
     * @param string $id Unique within the BilingualDocument. Used to link
     *     source/target segments and to reference segments in QA results.
     * @param array<string|InlineCode> $elements Ordered content. Example:
     *     ['Hello ', InlineCode(bold_open), 'world', InlineCode(bold_close), '!']
     *     Consecutive strings are allowed but discouraged (merge them).
     *     Empty array = empty segment.
     */
    public function __construct(
        public readonly string $id,
        private array $elements = [],
    ) {}

    /** Returns content as ordered array of strings and InlineCodes. */
    public function getElements(): array
    {
        return $this->elements;
    }

    /** Replaces all content elements. Used when translator edits the segment. */
    public function setElements(array $elements): void
    {
        $this->elements = $elements;
    }

    /**
     * Returns concatenated text content with all InlineCodes stripped.
     * Used for TM matching, terminology lookup, word count, and display.
     * Does not trim or normalize whitespace — callers normalize for their purpose.
     */
    public function getPlainText(): string
    {
        $text = '';
        foreach ($this->elements as $element) {
            if (is_string($element)) {
                $text .= $element;
            }
        }
        return $text;
    }

    /**
     * True if the segment contains no meaningful text.
     * Whitespace-only and inline-code-only segments are considered empty,
     * matching Wordfast/Trados behaviour where such segments are skipped.
     */
    public function isEmpty(): bool
    {
        return trim($this->getPlainText()) === '';
    }

    /**
     * Returns the InlineCode objects in order, without text.
     * Used by QA tag-consistency checks and XLIFF serialization.
     *
     * @return InlineCode[]
     */
    public function getInlineCodes(): array
    {
        return array_values(
            array_filter($this->elements, fn($e) => $e instanceof InlineCode)
        );
    }
}
