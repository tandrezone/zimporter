<?php
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();
// Database configuration
$host    = $_ENV['DB_HOST'] ?? '127.0.0.1';
$db      = $_ENV['DB_NAME'] ?? 'product_management';
$user    = $_ENV['DB_USER'] ?? 'manager';
$pass    = $_ENV['DB_PASS'] ?? 'manager';
$charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

// Initial connection without DB name to create the database first
$dsn = "mysql:host=$host;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    // 1. Create Database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db` ");
    echo "Database '$db' initialized.\n";

    // 2. Define Table Schemas
    $tables = [
        "categories" => "
            CREATE TABLE IF NOT EXISTS categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                slug VARCHAR(255) UNIQUE NOT NULL,
                description TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",

        "products" => "
            CREATE TABLE IF NOT EXISTS products (
                id INT AUTO_INCREMENT PRIMARY KEY,
                item_code VARCHAR(100) UNIQUE NOT NULL,
                name VARCHAR(255) NOT NULL,
                slug VARCHAR(255) UNIQUE NOT NULL,
                description TEXT,
                category_id INT,
                status ENUM('active', 'inactive', 'archived') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
            ) ENGINE=InnoDB",

        "product_measurements" => "
            CREATE TABLE IF NOT EXISTS product_measurements (
                product_id INT PRIMARY KEY,
                weight_grams DECIMAL(10, 2),
                length_mm DECIMAL(10, 2),
                width_mm DECIMAL(10, 2),
                height_mm DECIMAL(10, 2),
                volume_ml DECIMAL(10, 2),
                CONSTRAINT fk_measurements_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
            ) ENGINE=InnoDB",

        "product_specifications" => "
            CREATE TABLE IF NOT EXISTS product_specifications (
                product_id INT PRIMARY KEY,
                decoration_settings JSON,
                sustainability_metrics JSON,
                technical_specs JSON,
                CONSTRAINT fk_specs_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
                CONSTRAINT check_json_decoration CHECK (JSON_VALID(decoration_settings)),
                CONSTRAINT check_json_sustainability CHECK (JSON_VALID(sustainability_metrics))
            ) ENGINE=InnoDB",

        "product_variants" => "
            CREATE TABLE IF NOT EXISTS product_variants (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                sku VARCHAR(100) UNIQUE NOT NULL,
                variant_name VARCHAR(255),
                price DECIMAL(12, 2),
                stock_quantity INT DEFAULT 0,
                attributes JSON,
                CONSTRAINT fk_variant_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
            ) ENGINE=InnoDB",

        "admin_users" => "
            CREATE TABLE IF NOT EXISTS admin_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(100) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",

        "payment_gateways" => "
            CREATE TABLE IF NOT EXISTS payment_gateways (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(50) UNIQUE NOT NULL,
                name VARCHAR(255) NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                config JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",

        "shipping_methods" => "
            CREATE TABLE IF NOT EXISTS shipping_methods (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(50) UNIQUE NOT NULL,
                name VARCHAR(255) NOT NULL,
                description VARCHAR(500),
                price DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB"
    ];

    // 3. Execute Table Creation
    foreach ($tables as $name => $sql) {
        $pdo->exec($sql);
        echo "Table '$name' checked/created successfully.\n";
    }

    // 4. Add Indexes
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_item_code ON products(item_code)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_variant_sku ON product_variants(sku)");
    echo "Performance indexes applied.\n";

    // 5. Seed payment gateways and shipping methods
    $gatewayCount = (int) $pdo->query('SELECT COUNT(*) FROM payment_gateways')->fetchColumn();
    if ($gatewayCount === 0) {
        $pdo->exec("
            INSERT INTO payment_gateways (code, name, enabled, is_default, sort_order, config)
            VALUES ('oxo', 'Crypto Payment (Oxo Pay)', 1, 1, 0, '{}')
        ");
        echo "Default payment gateway seeded.\n";
    }

    $shippingCount = (int) $pdo->query('SELECT COUNT(*) FROM shipping_methods')->fetchColumn();
    if ($shippingCount === 0) {
        $pdo->exec("
            INSERT INTO shipping_methods (code, name, description, price, enabled, sort_order) VALUES
            ('standard', 'Standard Shipping', '3-5 business days', 10.00, 1, 0),
            ('express', 'Express Shipping', '1-2 business days', 25.50, 1, 1),
            ('pickup', 'In-store Pickup', 'Free', 0.00, 1, 2)
        ");
        echo "Default shipping methods seeded.\n";
    }

    echo "\nSchema setup complete. You can now run the import script.\n";
    echo "Create an admin user with: php commands/create-admin.php\n";

} catch (\PDOException $e) {
    die("Setup failed: " . $e->getMessage());
}
