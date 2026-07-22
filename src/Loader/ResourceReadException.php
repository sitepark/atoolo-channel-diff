<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Loader;

use RuntimeException;
use Throwable;

/**
 * Thrown when a resource PHP file cannot be evaluated into an array.
 */
final class ResourceReadException extends RuntimeException
{
    public function __construct(
        public readonly string $resourceFile,
        string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Could not read resource "%s": %s', $resourceFile, $reason),
            0,
            $previous,
        );
    }
}
