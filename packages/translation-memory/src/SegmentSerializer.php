<?php

declare(strict_types=1);

namespace CatFramework\TranslationMemory;

use CatFramework\Core\Enum\InlineCodeType;
use CatFramework\Core\Model\InlineCode;
use CatFramework\Core\Model\Segment;

class SegmentSerializer
{
    public static function serialize(Segment $segment): string
    {
        $data = [
            'id'       => $segment->id,
            'elements' => array_map(
                static fn($el) => is_string($el) ? $el : [
                    '_type'       => 'ic',
                    'id'          => $el->id,
                    'type'        => $el->type->value,
                    'data'        => $el->data,
                    'displayText' => $el->displayText,
                    'isIsolated'  => $el->isIsolated,
                ],
                $segment->getElements(),
            ),
        ];

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function deserialize(string $json): Segment
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $elements = array_map(
            static fn($el) => is_string($el) ? $el : new InlineCode(
                id:          $el['id'],
                type:        InlineCodeType::from($el['type']),
                data:        $el['data'],
                displayText: $el['displayText'],
                isIsolated:  $el['isIsolated'],
            ),
            $data['elements'],
        );

        return new Segment($data['id'], $elements);
    }
}
