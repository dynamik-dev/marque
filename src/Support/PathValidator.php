<?php

declare(strict_types=1);

namespace DynamikDev\Marque\Support;

use InvalidArgumentException;
use RuntimeException;

class PathValidator
{
    /**
     * Validate that a file path is within the configured allowed directory.
     *
     * `marque.document_path` MUST be configured before any document import or
     * export call that supplies a filesystem path. When unset, this validator
     * fails closed: no path is accepted. This prevents a default-config
     * deployment from allowing reads/writes of arbitrary files (e.g. /etc/passwd).
     *
     * @throws RuntimeException If `marque.document_path` is not configured.
     * @throws InvalidArgumentException If the path is outside the allowed directory.
     */
    public static function validate(string $path): string
    {
        /** @var string|null $allowedBase */
        $allowedBase = config('marque.document_path');

        if ($allowedBase === null || $allowedBase === '') {
            throw new RuntimeException(
                'Marque document path is not configured. Set [marque.document_path] to an absolute directory before importing or exporting policy documents.'
            );
        }

        $allowedBaseReal = realpath($allowedBase);
        $targetDirectoryReal = realpath(dirname($path));

        if ($allowedBaseReal === false || $targetDirectoryReal === false) {
            throw new InvalidArgumentException("Path must be within the allowed directory [{$allowedBase}].");
        }

        $allowedPrefix = rtrim($allowedBaseReal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $targetPrefix = rtrim($targetDirectoryReal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (! str_starts_with($targetPrefix, $allowedPrefix)) {
            throw new InvalidArgumentException("Path must be within the allowed directory [{$allowedBase}].");
        }

        $resolvedPath = $targetDirectoryReal.DIRECTORY_SEPARATOR.basename($path);

        // If the file exists, resolve symlinks on the full path and re-verify.
        if (file_exists($resolvedPath)) {
            $fullReal = realpath($resolvedPath);

            if ($fullReal === false || ! str_starts_with($fullReal, rtrim($allowedBaseReal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
                throw new InvalidArgumentException("Path must be within the allowed directory [{$allowedBase}].");
            }

            return $fullReal;
        }

        return $resolvedPath;
    }
}
