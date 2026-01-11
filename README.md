# CrisperCode Framework

Reusable building blocks for Slim 4 applications: entities, schema sync, auth helpers, middleware, Twig utilities, and envelope encryption.

## Requirements

- PHP 8.4+
- A supported database driver: MySQL/MariaDB, SQLite, or PostgreSQL

## Install

```bash
composer require crispercode/framework
```

## Quick start

### 1) Configure and create a database connection

```php
use CrisperCode\Config\FrameworkConfig;
use CrisperCode\Database\DatabaseFactory;

$config = new FrameworkConfig(
    rootPath: __DIR__,
    environment: 'development',
    appName: 'MyApp',
    dbDriver: 'mysql',
    dbHost: 'localhost',
    dbName: 'myapp',
    dbUser: 'user',
    dbPassword: 'password',
);

$db = DatabaseFactory::create($config);
```

SQLite example:

```php
$config = new FrameworkConfig(
    rootPath: __DIR__,
    environment: 'development',
    appName: 'MyApp',
    dbDriver: 'sqlite',
    dbName: __DIR__ . '/database.sqlite',
);

$db = DatabaseFactory::create($config);
```

### 2) Define an entity

```php
use CrisperCode\Attribute\Column;
use CrisperCode\Entity\EntityBase;

final class Post extends EntityBase
{
    public const TABLE_NAME = 'posts';

    #[Column(type: 'INT', primaryKey: true, autoIncrement: true)]
    public int $id;

    #[Column(type: 'VARCHAR', length: 255)]
    public string $title;

    #[Column(type: 'TEXT')]
    public string $content;
}
```

### 3) Sync schema

This package provides a Symfony Console command for generating/updating tables based on entity attributes:

```bash
php bin/console --help
php bin/console schema:sync
```

### Environment variables (optional)

If you prefer, you can configure database settings via environment variables:

- `DB_DRIVER`, `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_PORT`
- `DB_DSN` (overrides the above)

Legacy `MYSQL_*` variables are also supported.

## Testing (contributors)

```bash
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/phpcs
```

## License

MIT. See [LICENSE](LICENSE).
