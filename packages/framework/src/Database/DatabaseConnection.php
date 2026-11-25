<?php

declare(strict_types=1);

namespace Atom\Database;

use Atom\Database\Orm\PdoConnection;

final class DatabaseConnection extends PdoConnection
{
    public function __construct(private readonly DatabaseDriverInterface $driver)
    {
        parent::__construct($driver->connect(...), $driver->compiler());
    }

    public function driver(): DatabaseDriverInterface
    {
        return $this->driver;
    }
}
