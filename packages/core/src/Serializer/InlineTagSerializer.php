<?php

declare(strict_types=1);

namespace CatFramework\Core\Serializer;

use CatFramework\Core\Enum\InlineCodeType;
use CatFramework\Core\Model\InlineCode;
use CatFramework\Core\Model\Segment;

class InlineTagSerializer
{
    /**
     * Serialize a Segment into plain text with {N}/{/N}/{N/} placeholders.
     *
     * The placeholder IDs are sequential integers starting at 1, reset per segment.
     * Opening and closing tags that share an InlineCode->id get the same placeholder ID.
     */
    public static function serialize(Segment $segment): SerializedSegment
    {
        $text      = '';
        $tagMap    = [];
        $counter   = 1;
        $idMapping = []; // InlineCode->id (string) => placeholder int

        foreach ($segment->getElements() as $element) {
            if (is_string($element)) {
                $text .= $element;
                continue;
            }

            /** @var InlineCode $element */
            switch ($element->type) {
                case InlineCodeType::OPENING:
                    $pid               = $counter++;
                    $idMapping[$element->id] = $pid;
                    $text             .= '{' . $pid . '}';
                    $tagMap[]          = [
                        'id'          => $pid,
                        'type'        => 'open',
                        'data'        => $element->data,
                        'displayText' => $element->displayText ?? self::guessLabel($element->data),
                    ];
                    break;

                case InlineCodeType::CLOSING:
                    // Reuse the ID assigned to the matching opening tag.
                    $pid    = $idMapping[$element->id] ?? $counter++;
                    $text  .= '{/' . $pid . '}';
                    $tagMap[] = [
                        'id'          => $pid,
                        'type'        => 'close',
                        'data'        => $element->data,
                        'displayText' => $element->displayText ?? self::guessLabel($element->data),
                    ];
                    break;

                case InlineCodeType::STANDALONE:
                    $pid    = $counter++;
                    $text  .= '{' . $pid . '/}';
                    $tagMap[] = [
                        'id'          => $pid,
                        'type'        => 'self',
                        'data'        => $element->data,
                        'displayText' => $element->displayText ?? self::guessLabel($element->data),
                    ];
                    break;
            }
        }

        return new SerializedSegment(text: $text, tagMap: $tagMap);
    }

    /**
     * Deserialize placeholder text + tag map back into a Segment.
     *
     * @param string  $text   Text with {N}, {/N}, {N/} placeholders.
     * @param array[] $tagMap Tag descriptors as returned by serialize().
     */
    public static function deserialize(string $text, array $tagMap, string $segmentId = ''): Segment
    {
        // Build a lookup from placeholder ID + type to tag descriptor.
        $lookup = [];
        foreach ($tagMap as $tag) {
            $lookup[$tag['id']][$tag['type']] = $tag;
        }

        $elements = [];
        // Split on placeholder pattern, keeping delimiters.
        $parts = preg_split('/(\{\/?\d+\/?})/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (!preg_match('/^\{(\/?)(\d+)(\/?)}\z/', $part, $m)) {
                $elements[] = $part;
                continue;
            }

            [, $leadSlash, $numStr, $trailSlash] = $m;
            $pid = (int) $numStr;

            if ($leadSlash === '/') {
                // Closing: {/N}
                $tag  = $lookup[$pid]['close'] ?? null;
                $type = InlineCodeType::CLOSING;
            } elseif ($trailSlash === '/') {
                // Self-closing: {N/}
                $tag  = $lookup[$pid]['self'] ?? null;
                $type = InlineCodeType::STANDALONE;
            } else {
                // Opening: {N}
                $tag  = $lookup[$pid]['open'] ?? null;
                $type = InlineCodeType::OPENING;
            }

            $elements[] = new InlineCode(
                id:          (string) $pid,
                type:        $type,
                data:        $tag['data'] ?? '',
                displayText: $tag['displayText'] ?? null,
            );
        }

        return new Segment(id: $segmentId, elements: $elements);
    }

    /**
     * Guess a display label from raw tag data. Strips namespace prefixes and angle brackets.
     * "<b>" → "b", "<w:r>" → "r", "<br/>" → "br/"
     */
    private static function guessLabel(string $data): string
    {
        preg_match('/<\/?([a-zA-Z][a-zA-Z0-9:]*)(\/?)/', $data, $m);

        if ($m === []) {
            return '?';
        }

        // Strip namespace prefix, keep local name + self-close indicator.
        $local     = preg_replace('/^[a-zA-Z]+:/', '', $m[1]);
        $selfClose = $m[2] ?? '';

        return $local . $selfClose;
    }
}
