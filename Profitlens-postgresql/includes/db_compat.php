<?php
/**
 * ── MySQLi → PostgreSQL compatibility shim ──────────────────────────────
 *
 * Profit Lens was originally written against mysqli (MySQL). This file lets
 * every existing page keep calling the same familiar API —
 *   $db->query(), $db->prepare(), $stmt->bind_param(), ->fetch_assoc(),
 *   ->num_rows, ->close(), $db->begin_transaction()/commit()/rollback()...
 * — completely unchanged, while actually running on PostgreSQL underneath.
 *
 * Every SQL string passed to query()/prepare() is run through
 * PgDBCompat::translateSql() first, which rewrites MySQL-only syntax
 * (YEAR(), MONTH(), DATE_FORMAT(), CURDATE(), DATE_SUB/ADD, backticked
 * identifiers, SHOW COLUMNS, INSERT IGNORE, AUTO_INCREMENT, DATETIME, etc.)
 * into the PostgreSQL equivalent, then hands it to PDO.
 *
 * You should not need to touch this file for normal use. If you add a NEW
 * page with a MySQL-only SQL function this shim doesn't know about yet,
 * add a translation rule to translateSql() below rather than special-casing
 * it in the page itself.
 */

class PgDBCompat
{
    public $pdo;
    public $connect_error = null;
    public $insert_id = 0;
    public $affected_rows = 0;

    public function __construct($dsn, $user, $pass, $options = [])
    {
        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options + [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            $this->connect_error = $e->getMessage();
        }
    }

    /**
     * Rewrites a MySQL-flavoured SQL string into PostgreSQL-flavoured SQL.
     */
    public static function translateSql($sql)
    {
        $s = $sql;

        // Backticked identifiers (`table`, `col`) -> unquoted. Safe here
        // because every table/column name in this project is lowercase,
        // which is exactly how PostgreSQL folds unquoted identifiers.
        $s = str_replace('`', '', $s);

        // MySQL loosely allows `date_col != ''` (empty string == "no value").
        // PostgreSQL errors trying to cast '' to a date, so drop that check —
        // the accompanying `IS NOT NULL` already covers the "has a value" case.
        $s = preg_replace('/\s+AND\s+([a-zA-Z_]*date[a-zA-Z_]*)\s*!=\s*\'\'/i', '', $s);

        // SET FOREIGN_KEY_CHECKS=0/1 has no PostgreSQL equivalent for TRUNCATE,
        // so system_reset.php avoids needing it at all (see that file). If it
        // ever shows up elsewhere, turn it into a harmless no-op rather than
        // erroring out.
        if (preg_match('/^\s*SET\s+FOREIGN_KEY_CHECKS\s*=\s*[01]\s*;?\s*$/i', $s)) {
            return 'SELECT 1';
        }

        // INSERT IGNORE INTO ...  ->  INSERT INTO ... ON CONFLICT DO NOTHING
        if (preg_match('/^\s*INSERT\s+IGNORE\s+INTO/i', $s)) {
            $s = preg_replace('/^\s*INSERT\s+IGNORE\s+INTO/i', 'INSERT INTO', $s);
            $s = rtrim($s, "; \t\n\r") . ' ON CONFLICT DO NOTHING';
        }

        // ON DUPLICATE KEY UPDATE monthly_limit = VALUES(monthly_limit)
        // (only used for category_budgets, whose primary key is `category`)
        $s = preg_replace(
            '/ON\s+DUPLICATE\s+KEY\s+UPDATE\s+monthly_limit\s*=\s*VALUES\(monthly_limit\)/i',
            'ON CONFLICT (category) DO UPDATE SET monthly_limit = EXCLUDED.monthly_limit',
            $s
        );

        // SHOW COLUMNS FROM tbl LIKE 'col'
        $s = preg_replace_callback(
            '/SHOW\s+COLUMNS\s+FROM\s+([a-zA-Z_]+)\s+LIKE\s+\'([^\']+)\'/i',
            function ($m) {
                return "SELECT column_name FROM information_schema.columns "
                     . "WHERE table_name='{$m[1]}' AND column_name='{$m[2]}'";
            },
            $s
        );

        // ALTER TABLE tbl AUTO_INCREMENT = N  ->  reset the SERIAL sequence
        $s = preg_replace_callback(
            '/ALTER\s+TABLE\s+([a-zA-Z_]+)\s+AUTO_INCREMENT\s*=\s*(\d+)/i',
            function ($m) {
                return "SELECT setval(pg_get_serial_sequence('{$m[1]}','id'), {$m[2]}, false)";
            },
            $s
        );

        // CREATE TABLE ... id INT AUTO_INCREMENT PRIMARY KEY -> id SERIAL PRIMARY KEY
        $s = preg_replace('/\bINT\s+AUTO_INCREMENT\s+PRIMARY\s+KEY\b/i', 'SERIAL PRIMARY KEY', $s);
        $s = preg_replace('/\bAUTO_INCREMENT\b/i', '', $s);
        $s = preg_replace('/\bDATETIME\b/i', 'TIMESTAMP', $s);
        $s = preg_replace('/\bLONGTEXT\b/i', 'TEXT', $s);

        // CURDATE() -> CURRENT_DATE
        $s = preg_replace('/\bCURDATE\(\)/i', 'CURRENT_DATE', $s);

        // DATE_SUB(x, INTERVAL n UNIT) -> (x - INTERVAL 'n UNIT')
        $s = preg_replace('/DATE_SUB\(\s*([^,]+?)\s*,\s*INTERVAL\s+(\d+)\s+(DAY|MONTH|YEAR)\s*\)/i', "($1 - INTERVAL '$2 $3')", $s);
        // DATE_ADD(x, INTERVAL n UNIT) -> (x + INTERVAL 'n UNIT')
        $s = preg_replace('/DATE_ADD\(\s*([^,]+?)\s*,\s*INTERVAL\s+(\d+)\s+(DAY|MONTH|YEAR)\s*\)/i', "($1 + INTERVAL '$2 $3')", $s);

        // DATE_FORMAT(col, '%Y-%m')  -> TO_CHAR(col,'YYYY-MM')
        // DATE_FORMAT(col, '%M %Y')  -> TO_CHAR(col,'FMMonth YYYY')
        $s = preg_replace_callback(
            '/DATE_FORMAT\(\s*([a-zA-Z0-9_.]+)\s*,\s*\'([^\']+)\'\s*\)/i',
            function ($m) {
                $fmt = str_replace(['%Y', '%m', '%M', '%d'], ['YYYY', 'MM', 'FMMonth', 'DD'], $m[2]);
                return "TO_CHAR({$m[1]}, '$fmt')";
            },
            $s
        );

        // YEAR(col) -> EXTRACT(YEAR FROM col)::int   (handles one level of nesting,
        // e.g. YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) after the DATE_SUB rewrite above)
        $s = preg_replace('/\bYEAR\(((?:[^()]|\([^()]*\))*)\)/i', 'EXTRACT(YEAR FROM $1)::int', $s);
        // MONTH(col) -> EXTRACT(MONTH FROM col)::int
        $s = preg_replace('/\bMONTH\(((?:[^()]|\([^()]*\))*)\)/i', 'EXTRACT(MONTH FROM $1)::int', $s);

        return $s;
    }

    public function query($sql)
    {
        $translated = self::translateSql($sql);
        try {
            $stmt = $this->pdo->query($translated);
            $this->affected_rows = $stmt->rowCount();
            return new PgResultCompat($stmt);
        } catch (PDOException $e) {
            trigger_error('SQL error: ' . $e->getMessage() . ' | Query: ' . $translated, E_USER_WARNING);
            return false;
        }
    }

    public function prepare($sql)
    {
        $translated = self::translateSql($sql);
        try {
            $stmt = $this->pdo->prepare($translated);
            return new PgStmtCompat($stmt, $this);
        } catch (PDOException $e) {
            trigger_error('SQL prepare error: ' . $e->getMessage() . ' | Query: ' . $translated, E_USER_WARNING);
            return false;
        }
    }

    public function begin_transaction() { return $this->pdo->beginTransaction(); }
    public function commit()            { return $this->pdo->commit(); }
    public function rollback()          { return $this->pdo->rollBack(); }
    public function close()             { $this->pdo = null; }
    public function set_charset($c)     { /* no-op: PDO pgsql connection is UTF-8 already */ }

    public function real_escape_string($s)
    {
        return substr($this->pdo->quote($s), 1, -1);
    }
}

class PgResultCompat
{
    private $rows;
    private $pos = 0;
    public $num_rows;

    public function __construct($stmt)
    {
        try {
            $this->rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->rows = [];
        }
        $this->num_rows = count($this->rows);
    }

    public function fetch_assoc()
    {
        if ($this->pos >= $this->num_rows) return null;
        return $this->rows[$this->pos++];
    }

    public function fetch_all() { return $this->rows; }
}

class PgStmtCompat
{
    private $stmt;
    private $db;
    private $params = [];
    private $result = null;
    public $num_rows = 0;
    public $affected_rows = 0;

    public function __construct($stmt, $db)
    {
        $this->stmt = $stmt;
        $this->db   = $db;
    }

    // mysqli-style: bind_param('sdi', $a, $b, $c). The type string is
    // ignored — PDO binds '?' placeholders positionally regardless of type.
    public function bind_param($types, &...$vars)
    {
        $this->params = $vars;
        return true;
    }

    public function execute()
    {
        try {
            $ok = $this->stmt->execute(array_values($this->params));
            $this->affected_rows = $this->stmt->rowCount();
            $this->result = null;
            if ($ok && $this->stmt->columnCount() > 0) {
                $this->result   = new PgResultCompat($this->stmt);
                $this->num_rows = $this->result->num_rows;
            }
            return $ok;
        } catch (PDOException $e) {
            trigger_error('SQL execute error: ' . $e->getMessage(), E_USER_WARNING);
            return false;
        }
    }

    public function get_result()    { return $this->result ?: new PgResultCompat($this->stmt); }
    public function store_result()  { /* results are already buffered eagerly, no-op */ }
    public function fetch_assoc()   { return $this->result ? $this->result->fetch_assoc() : null; }
    public function close()         { $this->stmt = null; }
}
