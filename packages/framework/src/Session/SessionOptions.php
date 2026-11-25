<?php

declare(strict_types=1);

namespace Atom\Session;

use Atom\Config\Options;
use InvalidArgumentException;

#[Options("SESSION_")]
final readonly class SessionOptions
{
    public function __construct(
        public string $name = "ATOMSESSID",
        public int $lifetime = 0,
        public string $path = "/",
        public string $domain = "",
        public ?bool $secure = null,
        public bool $httpOnly = true,
        public string $sameSite = "Lax",
        public bool $strictMode = true,
        public int $idleTimeout = 0
    ) {
        if ($idleTimeout < 0) {
            throw new InvalidArgumentException("Session idle timeout cannot be negative.");
        }
    }
}
