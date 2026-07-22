<?php

declare(strict_types=1);

namespace Atoolo\ChannelDiff\Loader;

use Atoolo\Resource\Loader\SiteKit\ContextStub;
use Atoolo\Resource\Loader\SiteKit\LifecylceStub;
use Throwable;

/**
 * Evaluates a SiteKit resource PHP file into its raw nested array.
 *
 * This deliberately mirrors the private {@see \Atoolo\Resource\Loader\SiteKitLoader::loadRaw()}:
 * the SiteKit resource scripts expect `$context` and `$lifecycle` to be present
 * in scope (the bootstrap `if (!isset($context)) {...}` guards then skip loading
 * the real runtime), so the bundle's public stub objects are provided here.
 *
 * Unlike SiteKitLoader::load(), this works directly with a physical file path
 * and needs no URL/ID path resolution, so it is identical for all three
 * channel layouts. Both file styles are supported: a flat `return [...]` file
 * returns its array directly, a lifecycle-bootstrap file returns the merged
 * array via `$lifecycle->service($resource)`.
 */
final class ResourceFileReader
{
    /**
     * @return array<string, mixed>
     * @throws ResourceReadException
     */
    public function read(string $file): array
    {
        if (!is_file($file)) {
            throw new ResourceReadException($file, 'file does not exist');
        }

        // Must be defined before `require`; used by the required resource script.
        $context = new ContextStub();
        $lifecycle = new LifecylceStub();

        $saveErrorReporting = error_reporting();
        ob_start();
        try {
            error_reporting(E_ERROR | E_PARSE);
            $data = require $file;
        } catch (Throwable $e) {
            throw new ResourceReadException($file, $e->getMessage(), $e);
        } finally {
            ob_end_clean();
            error_reporting($saveErrorReporting);
        }

        if (!is_array($data)) {
            throw new ResourceReadException($file, 'resource did not return an array');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
