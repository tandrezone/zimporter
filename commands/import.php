<?php
/**
 * Product JSON Import Script
 *
 * Imports products, categories, and variants from a JSON file into the database.
 *
 * Usage:
 *   php commands/import.php <path-to-json-file>
 *
 * Example:
 *   php commands/import.php data/products.json
 */

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// ── Database configuration ────────────────────────────────────────────────────
$host    = $_ENV['DB_HOST']    ?? '127.0.0.1';
$db      = $_ENV['DB_NAME']    ?? 'product_management';
$user    = $_ENV['DB_USER']    ?? 'manager';
$pass    = $_ENV['DB_PASS']    ?? 'manager';
$charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

// ── Validate CLI argument ─────────────────────────────────────────────────────
if ($argc < 2) {
    fwrite(STDERR, "Usage: php commands/import.php <path-to-json-file>\n");
    exit(1);
}

$jsonFile = $argv[1];

if (!file_exists($jsonFile)) {
    fwrite(STDERR, "Error: File not found: $jsonFile\n");
    exit(1);
}

// ── Load and parse JSON ───────────────────────────────────────────────────────
$raw = file_get_contents($jsonFile);
if ($raw === false) {
    fwrite(STDERR, "Error: Could not read file: $jsonFile\n");
    exit(1);
}

$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    fwrite(STDERR, "Error: Invalid JSON – " . json_last_error_msg() . "\n");
    exit(1);
}

if (empty($data['products']) || !is_array($data['products'])) {
    fwrite(STDERR, "Error: JSON must contain a top-level 'products' array.\n");
    exit(1);
}

$products = $data['products'];

// ── Connect to database ───────────────────────────────────────────────────────
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Run 'php commands/setup-db.php' first to initialize the database.\n");
    exit(1);
}

// ── Counters ──────────────────────────────────────────────────────────────────
$stats = [
    'categories_inserted' => 0,
    'categories_updated'  => 0,
    'products_inserted'   => 0,
    'products_updated'    => 0,
    'variants_inserted'   => 0,
    'variants_updated'    => 0,
    'errors'              => 0,
];

// ── UUID → integer ID cache (avoids repeated SELECT lookups) ─────────────────
$categoryIdMap = [];   // source UUID → local integer id
$productIdMap  = [];   // source UUID → local integer id

// ── Prepared statements ───────────────────────────────────────────────────────
$stmts = prepareStatements($pdo);

// ── Process each product ──────────────────────────────────────────────────────
foreach ($products as $index => $product) {
    try {
        $pdo->beginTransaction();

        // 1. Upsert category
        $categoryIntId = null;
        if (!empty($product['category']) && is_array($product['category'])) {
            $categoryIntId = upsertCategory($pdo, $stmts, $product['category'], $categoryIdMap, $stats);
        }

        // 2. Upsert product
        $productIntId = upsertProduct($pdo, $stmts, $product, $categoryIntId, $productIdMap, $stats);

        // 3. Upsert variants
        if ($productIntId !== null && !empty($product['variants']) && is_array($product['variants'])) {
            foreach ($product['variants'] as $variant) {
                upsertVariant($pdo, $stmts, $variant, $productIntId, $stats);
            }
        }

        $pdo->commit();

    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $productName = $product['name'] ?? "(unknown, index $index)";
        fwrite(STDERR, "  [ERROR] Product '$productName': " . $e->getMessage() . "\n");
        $stats['errors']++;
    }
}

// ── Report results ────────────────────────────────────────────────────────────
echo "\nImport complete.\n";
echo "  Categories : {$stats['categories_inserted']} inserted, {$stats['categories_updated']} updated\n";
echo "  Products   : {$stats['products_inserted']} inserted, {$stats['products_updated']} updated\n";
echo "  Variants   : {$stats['variants_inserted']} inserted, {$stats['variants_updated']} updated\n";
if ($stats['errors'] > 0) {
    echo "  Errors     : {$stats['errors']} (see messages above)\n";
    exit(2);
}

// ═════════════════════════════════════════════════════════════════════════════
// Helper functions
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Build and return all prepared statements used during import.
 */
function prepareStatements(PDO $pdo): array
{
    return [
        // categories
        'category_select' => $pdo->prepare(
            "SELECT id FROM categories WHERE slug = ?"
        ),
        'category_insert' => $pdo->prepare(
            "INSERT INTO categories (name, slug, description)
             VALUES (:name, :slug, :description)
             ON DUPLICATE KEY UPDATE
                 name        = VALUES(name),
                 description = VALUES(description)"
        ),

        // products
        'product_select' => $pdo->prepare(
            "SELECT id FROM products WHERE item_code = ?"
        ),
        'product_insert' => $pdo->prepare(
            "INSERT INTO products
                 (item_code, name, slug, description, category_id, status, created_at, updated_at)
             VALUES
                 (:item_code, :name, :slug, :description, :category_id, :status, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                 name        = VALUES(name),
                 slug        = VALUES(slug),
                 description = VALUES(description),
                 category_id = VALUES(category_id),
                 status      = VALUES(status),
                 updated_at  = VALUES(updated_at)"
        ),

        // product_variants
        'variant_select' => $pdo->prepare(
            "SELECT id FROM product_variants WHERE sku = ?"
        ),
        'variant_insert' => $pdo->prepare(
            "INSERT INTO product_variants
                 (product_id, sku, variant_name, price, stock_quantity, attributes)
             VALUES
                 (:product_id, :sku, :variant_name, :price, :stock_quantity, :attributes)
             ON DUPLICATE KEY UPDATE
                 product_id     = VALUES(product_id),
                 variant_name   = VALUES(variant_name),
                 price          = VALUES(price),
                 stock_quantity = VALUES(stock_quantity),
                 attributes     = VALUES(attributes)"
        ),
    ];
}

/**
 * Upsert a category row and return its local integer ID.
 *
 * @param array<string,int> $categoryIdMap  UUID → int id cache (modified in place)
 * @param array<string,int> $stats          Stats counters (modified in place)
 */
function upsertCategory(
    PDO    $pdo,
    array  $stmts,
    array  $cat,
    array  &$categoryIdMap,
    array  &$stats
): int {
    $uuid = $cat['id'] ?? null;

    // Return cached mapping if available
    if ($uuid !== null && isset($categoryIdMap[$uuid])) {
        return $categoryIdMap[$uuid];
    }

    $slug        = trim($cat['slug']        ?? '');
    $name        = trim($cat['name']        ?? '');
    $description = $cat['description']      ?? null;

    if ($slug === '' || $name === '') {
        throw new \RuntimeException("Category is missing required name/slug fields.");
    }

    // Check for existing row
    $stmts['category_select']->execute([$slug]);
    $existing = $stmts['category_select']->fetchColumn();

    $stmts['category_insert']->execute([
        ':name'        => $name,
        ':slug'        => $slug,
        ':description' => $description,
    ]);

    if ($existing !== false) {
        $intId = (int) $existing;
        $stats['categories_updated']++;
    } else {
        $intId = (int) $pdo->lastInsertId();
        $stats['categories_inserted']++;
    }

    if ($uuid !== null) {
        $categoryIdMap[$uuid] = $intId;
    }

    return $intId;
}

/**
 * Upsert a product row and return its local integer ID.
 *
 * The source UUID is used as `item_code` so the product can be identified
 * and updated on subsequent imports.
 *
 * @param array<string,int>      $productIdMap  UUID → int id cache (modified in place)
 * @param array<string,int>      $stats         Stats counters (modified in place)
 */
function upsertProduct(
    PDO    $pdo,
    array  $stmts,
    array  $product,
    ?int   $categoryIntId,
    array  &$productIdMap,
    array  &$stats
): int {
    $uuid      = $product['id']          ?? null;
    $name      = trim($product['name']   ?? '');
    $slug      = trim($product['slug']   ?? '');

    if ($uuid === null || $name === '' || $slug === '') {
        throw new \RuntimeException("Product is missing required id/name/slug fields.");
    }

    // Use the source UUID as the stable item_code
    $itemCode    = $uuid;
    $description = $product['description'] ?? null;
    $isActive    = $product['isActive']    ?? true;
    $status      = $isActive ? 'active' : 'inactive';

    $createdAt   = formatTimestamp($product['createdAt'] ?? null);
    $updatedAt   = formatTimestamp($product['updatedAt'] ?? null);

    // Check for existing row
    $stmts['product_select']->execute([$itemCode]);
    $existing = $stmts['product_select']->fetchColumn();

    $stmts['product_insert']->execute([
        ':item_code'   => $itemCode,
        ':name'        => $name,
        ':slug'        => $slug,
        ':description' => $description,
        ':category_id' => $categoryIntId,
        ':status'      => $status,
        ':created_at'  => $createdAt,
        ':updated_at'  => $updatedAt,
    ]);

    if ($existing !== false) {
        $intId = (int) $existing;
        $stats['products_updated']++;
    } else {
        $intId = (int) $pdo->lastInsertId();
        $stats['products_inserted']++;
    }

    $productIdMap[$uuid] = $intId;

    return $intId;
}

/**
 * Upsert a product variant row.
 *
 * The source variant UUID is used as the SKU when the original SKU is absent,
 * ensuring uniqueness and stable re-import behaviour.
 *
 * @param array<string,int> $stats  Stats counters (modified in place)
 */
function upsertVariant(
    PDO   $pdo,
    array $stmts,
    array $variant,
    int   $productIntId,
    array &$stats
): void {
    $variantUuid = $variant['id'] ?? null;

    if ($variantUuid === null) {
        throw new \RuntimeException("Variant is missing required 'id' field.");
    }

    // Prefer the source SKU; fall back to the variant UUID for uniqueness
    $sku = (isset($variant['sku']) && $variant['sku'] !== null && $variant['sku'] !== '')
        ? $variant['sku']
        : $variantUuid;

    $variantName   = $variant['label']  ?? null;
    $price         = isset($variant['price'])  ? (float) $variant['price']  : null;
    $stockQuantity = isset($variant['stock'])  ? (int)   $variant['stock']  : 0;

    // Store label, unit, and active flag as attributes JSON
    $attributes = json_encode([
        'label'    => $variant['label']    ?? null,
        'unit'     => $variant['unit']     ?? null,
        'isActive' => $variant['isActive'] ?? true,
    ], JSON_UNESCAPED_UNICODE);

    // Check for existing row
    $stmts['variant_select']->execute([$sku]);
    $existing = $stmts['variant_select']->fetchColumn();

    $stmts['variant_insert']->execute([
        ':product_id'     => $productIntId,
        ':sku'            => $sku,
        ':variant_name'   => $variantName,
        ':price'          => $price,
        ':stock_quantity' => $stockQuantity,
        ':attributes'     => $attributes,
    ]);

    if ($existing !== false) {
        $stats['variants_updated']++;
    } else {
        $stats['variants_inserted']++;
    }
}

/**
 * Convert an ISO 8601 timestamp string to MySQL DATETIME format.
 * Returns null if the input is null or unparseable.
 */
function formatTimestamp(?string $iso): ?string
{
    if ($iso === null || $iso === '') {
        return null;
    }
    $dt = \DateTime::createFromFormat(\DateTime::ATOM, $iso)
       ?: \DateTime::createFromFormat('Y-m-d\TH:i:s.vZ', $iso)
       ?: \DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', $iso);

    return $dt ? $dt->format('Y-m-d H:i:s') : null;
}
