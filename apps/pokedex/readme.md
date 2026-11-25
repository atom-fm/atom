# Pokédex and team builder

This sample uses Atom and SQLite to browse six seeded Pokémon and manage one shared team. API Explorer is the interface for the walkthrough.

## Run locally

From this directory, with PHP 8.4, Composer, and PDO SQLite enabled, run:

```shell
composer install
php atom migrate
php atom db:seed
php atom serve
```

Open [API Explorer](http://127.0.0.1:8020/atom/api). To use another port, pass it to `php atom serve` and follow the address printed in the terminal.

## Follow the code

1. [Migrations](app/Database/Migrations) create the catalog and team tables using Atom’s schema builder. The catalog uses stable Pokédex IDs. A unique column prevents duplicate team membership, and a foreign key ensures every member references a catalog entry. The controller looks up the Pokémon first to give API clients a `404` for an unknown ID. Existing sample databases gain the foreign key on the next `php atom migrate`.
2. The [seeder](app/Database/Seeders/S0001_seed_pokemon.php) uses SQLite’s `ON CONFLICT DO NOTHING` for existing Pokédex IDs. Run `php atom db:seed` again and list the catalog to see the same six records.
3. The [models](app/Models) map PHP properties to database columns. `Pokemon::query()` builds a filtered list and `Pokemon::find()` looks up a primary key. `TeamMember` declares a `BelongsTo` relation so the team query can eager-load Pokémon with `with("pokemon")`.
4. The [controllers](app/Controllers) use route attributes to expose those queries and call `save()` and `delete()` to change the team. They map models to [response DTOs](app/Api) with snake_case fields such as `primary_type` and `pokemon_id`. API Explorer reads `#[ResponseOf]` to show the payload fields for both lists and individual resources.

## Try it in API Explorer

1. Select `GET /api/pokemon`, set the request URL to `/api/pokemon?type=electric`, and click **Send**. The `items` array contains Pikachu. The response also shows `page`, `page_size`, `total`, and `total_pages`. Try `/api/pokemon?page=2` for the second page of the unfiltered catalog.
2. Select `GET /api/pokemon/{id}` and enter `/api/pokemon/25`. Try ID `999` to see the `404` response.
3. Select `POST /api/team/members` and replace the request body with `{"pokemon_id":25}`. The response is `201 Created`. A regular HTTP client also receives a `Location` header for the new member.
4. Send `GET /api/team` to see the saved member and its Pokémon. Use the team member’s returned `id` in `GET /api/team/members/{id}` or `DELETE /api/team/members/{id}`. Deletion returns `204 No Content`.

Try posting the same Pokémon twice to get `409`, or send `{}` to get `422`.
