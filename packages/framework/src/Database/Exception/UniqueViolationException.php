<?php

declare(strict_types=1);

namespace Atom\Database\Exception;

use PDOException;

final class UniqueViolationException extends PDOException
{
    public function __construct(PDOException $previous)
    {
        parent::__construct($previous->getMessage(), previous: $previous);
        $this->code = $previous->getCode();
        $this->errorInfo = $previous->errorInfo;
    }
}
