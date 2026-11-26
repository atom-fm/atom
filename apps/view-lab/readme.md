# Atom View Lab

Use this standalone project to develop and exercise View components. Composer links to the monorepo's View package so source changes are visible on the next run.

From this directory, install the dependency and run the checks.

```powershell
composer install
composer check
```

To preview the fixture in a browser, start PHP's local server and open [Atom View Lab](http://127.0.0.1:8023).

```powershell
php -S 127.0.0.1:8023 -t public
```

Put a component class and its adjacent template in `app/`. Register its tag in `bootstrap.php` and add it to `views/preview.atom.html`. For an isolated check, call `renderComponent()` with the component class and properties in `check.php`.
