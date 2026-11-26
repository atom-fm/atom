<?php

declare(strict_types=1);

namespace App\Accounts;

use App\Models\User;
use Atom\Identity\IdentityInterface;
use Atom\Identity\IdentityProviderInterface;
use Atom\Identity\PasswordHasherInterface;
use Atom\Modules\Accounts\AccountManagerInterface;
use Atom\Modules\Accounts\RegisterAccount;
use SensitiveParameter;

final readonly class AccountManager implements AccountManagerInterface
{
    public function __construct(
        private IdentityProviderInterface $identities,
        private PasswordHasherInterface $passwords
    ) {}

    public function register(RegisterAccount $account): ?IdentityInterface
    {
        $login = strtolower(trim($account->login));
        if ($login === "" || $this->identities->findByLogin($login) !== null) {
            return null;
        }

        $user = new User();
        $user->email = $login;
        $user->name = trim($account->string("name"));
        $user->passwordHash = $this->passwords->hash($account->password());
        $user->save();
        return $user;
    }

    public function requestPasswordReset(string $login): void {}

    public function resetPassword(
        string $login,
        string $token,
        #[SensitiveParameter] string $password
    ): bool {
        return false;
    }
}
