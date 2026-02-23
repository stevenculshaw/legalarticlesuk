<?php
// ============================================================
// includes/db.php — PDO database connection (singleton)
// ============================================================
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function dbGet(string $sql, array $params = []): ?array {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetch() ?: null;
}

function dbAll(string $sql, array $params = []): array {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function dbRun(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function dbLastId(): string {
    return db()->lastInsertId();
}

function setting(string $key, string $default = ''): string {
    $row = dbGet('SELECT setting_value FROM system_settings WHERE setting_key = ?', [$key]);
    return $row ? $row['setting_value'] : $default;
}
