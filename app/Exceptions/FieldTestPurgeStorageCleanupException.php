<?php

namespace App\Exceptions;

use RuntimeException;

final class FieldTestPurgeStorageCleanupException extends RuntimeException
{
    public function __construct(public readonly string $cleanupPublicId)
    {
        parent::__construct('The database purge completed, but private storage cleanup is incomplete.');
    }
}
