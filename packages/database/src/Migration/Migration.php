<?php

declare(strict_types=1);

namespace Atom\Database\Migration;

use Atom\Database\Schema\Schema;
use RuntimeException;

abstract class Migration
{
    abstract public function up(Schema $schema): void;

    public function down(Schema $schema): void
    {
        throw new RuntimeException("This migration is irreversible because it does not implement down().");
    }
}
