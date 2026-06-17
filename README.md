# zimporter

PHP toolset for importing product data from a JSON export into a MySQL database.

## Requirements

- PHP 8.1+
- MySQL 8.0+ (or MariaDB 10.5+)
- Composer

## Setup

1. **Install dependencies**

   ```bash
   composer install
   ```

2. **Configure environment**

   ```bash
   cp .env.example .env
   # Edit .env with your database credentials
   ```

3. **Initialize the database schema**

   ```bash
   php commands/setup-db.php
   ```

## Importing products

```bash
php commands/import.php path/to/products.json
```

The script expects a JSON file with a top-level `products` array. Each product
entry must include at minimum: `id`, `name`, `slug`, and optionally a `category`
object and a `variants` array.

### What gets imported

| JSON field | Database table / column |
|---|---|
| `category` object | `categories` (upserted by `slug`) |
| product fields | `products` (upserted by `item_code = product UUID`) |
| `variants[]` | `product_variants` (upserted by `sku`, falls back to variant UUID) |

### Exit codes

| Code | Meaning |
|---|---|
| `0` | All records imported successfully |
| `1` | Usage / file / JSON error (no data written) |
| `2` | Import completed but at least one product failed |