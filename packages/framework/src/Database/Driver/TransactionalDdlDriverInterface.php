<?php

declare(strict_types=1);

namespace Atom\Database\Driver;

use Atom\Database\DatabaseDriverInterface;

/** The driver supports transactions and savepoints across schema commands and migration history writes. */
interface TransactionalDdlDriverInterface extends DatabaseDriverInterface
{
}
