<?php
require_once __DIR__ . '/db_compat.php';

/**
 * ── Database connection (PostgreSQL) ─────────────────────────────────
 *
 * On Render: your Postgres instance gives you a connection string like
 *   postgres://user:password@host:5432/dbname
 * Set that as the DATABASE_URL environment variable on your Web Service
 * (Render → your service → Environment). Use the "Internal Database URL"
 * if your web service and database are in the same Render region.
 *
 * Locally (XAMPP etc.): either set the same env vars, or just edit the
 * fallback values below to point at your local PostgreSQL install.
 */
function buildDsnFromDatabaseUrl($url)
{
    $parts = parse_url($url);
    $host  = $parts['host'] ?? 'localhost';
    $port  = $parts['port'] ?? 5432;
    $name  = ltrim($parts['path'] ?? '', '/');
    $user  = $parts['user'] ?? '';
    $pass  = $parts['pass'] ?? '';
    $dsn   = "pgsql:host=$host;port=$port;dbname=$name;sslmode=require";
    return [$dsn, $user, $pass];
}

if ($databaseUrl = getenv('DATABASE_URL')) {
    [$DB_DSN, $DB_USER, $DB_PASS] = buildDsnFromDatabaseUrl($databaseUrl);
} else {
    // ── Local fallback — edit these for your own machine if not using env vars ──
    $DB_HOST = getenv('DB_HOST') ?: 'localhost';
    $DB_PORT = getenv('DB_PORT') ?: '5432';
    $DB_NAME = getenv('DB_NAME') ?: 'gdr';
    $DB_USER = getenv('DB_USER') ?: 'postgres';
    $DB_PASS = getenv('DB_PASS') ?: '';
    $DB_DSN  = "pgsql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME";
}

function getDB()
{
    global $DB_DSN, $DB_USER, $DB_PASS;
    $db = new PgDBCompat($DB_DSN, $DB_USER, $DB_PASS);
    if ($db->connect_error) {
        die('Connection failed: ' . $db->connect_error);
    }
    return $db;
}

session_start();

function isLoggedIn()
{
    return isset($_SESSION['user_id']);
}

function isAdmin()
{
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function requireLogin()
{
    if (!isLoggedIn()) {
        header('Location: index.php');
        exit();
    }
}

function requireAdmin()
{
    if (!isLoggedIn()) {
        header('Location: index.php');
        exit();
    }
    if (!isAdmin()) {
        header('Location: access_denied.php');
        exit();
    }
}

function formatMoney($amount)
{
    return '₱' . number_format($amount, 2);
}
