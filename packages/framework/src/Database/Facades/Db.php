<?php

declare(strict_types=1);

namespace Atom\Database\Facades;

use Atom\Database\Db as Database;
use Atom\Database\DbSelect;
use Atom\Database\DefaultDatabase;

final class Db
{
    /**
     * @param array<string, DbSelect<*>> $queries
     * @return array<string, int>
     */
    public static function counts(array $queries): array
    {
        return DefaultDatabase::get()->counts($queries);
    }

    /**
     * @template T
     * @param callable(Database): T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        return DefaultDatabase::get()->transaction($callback);
    }
}
