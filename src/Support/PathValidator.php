<?php

declare(strict_types=1);

namespace DynamikDev\PolicyEngine\Support;

use InvalidArgumentException;

class PathValidator
{
    /**
     * Validate that a file path is within the configured allowed directory.
     *
     * When `policy-engine.document_path` is set, paths are restricted to that directory.
     * When null (default), no restriction is applied.
     *
     * @throws InvalidArgumentException If the path is outside the allowed directory.
     */
    public static function validate(string $path): string
    {
        /** @var string|null $allowedBase */
        $allowedBase = config('policy-engine.document_path');

        if ($allowedBase === null) {
            return $path;
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
