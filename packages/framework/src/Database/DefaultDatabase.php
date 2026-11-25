<?php

declare(strict_types=1);

namespace Atom\Database;

use RuntimeException;

/** @internal The database shared by models and database facades. */
final class DefaultDatabase
{
    private static ?Db $db = null;

    public static function set(Db $db): void
    {
        self::$db = $db;
    }

    public static function get(): Db
    {
        return self::$db ?? throw new RuntimeException("No database has been configured for models.");
    }
}
