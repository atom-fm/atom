# Atom Starter App

A minimal Atom application using SQLite and the Accounts login module.

```powershell
composer install
copy .env.example .env
php atom migrate
php atom accounts:user:create admin@example.com change-me "Admin User"
php -S 127.0.0.1:8020 -t public public/server.php
```

Open `http://127.0.0.1:8020/account/login` and sign in with the user created above.
