<?php

declare(strict_types=1);

namespace CatFramework\Core\Enum;

enum InlineCodeType: string
{
    /** Opening half of a paired tag, e.g. <b>, <a href="..."> */
    case OPENING = 'opening';

    /** Closing half of a paired tag, e.g. </b>, </a> */
    case CLOSING = 'closing';

    /** Self-closing tag with no pair, e.g. <br/>, <img/> */
    case STANDALONE = 'standalone';
}
