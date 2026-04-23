<?php

declare(strict_types=1);

namespace CatFramework\Mt\Exception;

use CatFramework\Core\Exception\MtException as CoreMtException;

/**
 * MT-specific exception extending core's MtException.
 * Use the code constants to distinguish error categories in catch blocks.
 */
class MtException extends CoreMtException
{
    public const int LANGUAGE_NOT_SUPPORTED = 1;
    public const int AUTH_FAILED            = 2;
    public const int QUOTA_EXCEEDED         = 3;
    public const int RATE_LIMITED           = 4;
    public const int BAD_REQUEST            = 5;
    public const int SERVER_ERROR           = 6;
}
