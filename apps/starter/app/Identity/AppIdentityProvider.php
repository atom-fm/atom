<?php

declare(strict_types=1);

namespace App\Identity;

use App\Models\User;
use Atom\Identity\IdentityInterface;
use Atom\Identity\IdentityProviderInterface;
use Atom\Identity\PasswordHasherInterface;
use SensitiveParameter;

final readonly class AppIdentityProvider implements IdentityProviderInterface
{
    public function __construct(private PasswordHasherInterface $passwords) {}

    public function findByIdentifier(string|int $identifier): ?IdentityInterface
    {
        return User::find($identifier);
    }

    public function findByLogin(string $login): ?IdentityInterface
    {
        $user = User::query()->where("email", strtolower(trim($login)))->first();
        return $user instanceof User ? $user : null;
    }

    public function validateCredentials(
        IdentityInterface $identity,
        #[SensitiveParameter] string $password
    ): bool {
        return $identity instanceof User
            && $this->passwords->verify($password, $identity->passwordHash);
    }
}
