<?php
date_default_timezone_set('Asia/Manila');

require_once 'includes/config.php';
requireAdmin();
$db = getDB();

// ---- Supporting tables ----
$db->query("CREATE TABLE IF NOT EXISTS category_budgets (
    category VARCHAR(50) PRIMARY KEY,
    monthly_limit DECIMAL(12,2) NOT NULL DEFAULT 0
)");
$db->query("CREATE TABLE IF NOT EXISTS deleted_expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    original_id INT,
    category VARCHAR(50),
    description TEXT,
    amount DECIMAL(12,2),
    expense_date DATE,
    deleted_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
// Stores a full snapshot of any tables cleared via a System Reset, so it can be restored later.
$db->query("CREATE TABLE IF NOT EXISTS system_backups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(255),
    tables_included VARCHAR(255),
    total_rows INT DEFAULT 0,
    backup_data LONGTEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$success = $error = '';
$action  = $_GET['action'] ?? '';

// Dataset key -> real table name(s)
$table_map = [
    'products' => ['products'],
    'sales'    => ['sales'],
    'expenses' => ['expenses', 'deleted_expenses'],
    'budgets'  => ['category_budgets'],
];
$dataset_labels = [
    'products' => '📦 Products',
    'sales'    => '🧾 Sales Records',
    'expenses' => '💸 Expenses & Deleted History',
    'budgets'  => '⚙️ Category Budget Settings',
];

// ---- Handle the reset (backup first, then truncate) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_reset'])) {
    $confirm_text = trim($_POST['confirm_text'] ?? '');
    $targets      = $_POST['targets'] ?? [];

    if ($confirm_text !== 'RESET') {
        $error = 'Confirmation phrase did not match. Nothing was deleted.';
    } elseif (empty($targets)) {
        $error = 'No data sets were selected. Nothing was deleted.';
    } else {
        $tables_to_clear = [];
        $dataset_names    = [];
        foreach ($targets as $t) {
            if (isset($table_map[$t])) {
                foreach ($table_map[$t] as $tbl) $tables_to_clear[] = $tbl;
                $dataset_names[] = $dataset_labels[$t] ?? $t;
            }
        }

        if (empty($tables_to_clear)) {
            $error = 'No valid data sets were selected.';
        } else {
            $db->begin_transaction();
            try {
                // 1. Snapshot every row from every table about to be cleared
                $snapshot   = [];
                $total_rows = 0;
                foreach ($tables_to_clear as $tbl) {
                    $rows = [];
                    $res = $db->query("SELECT * FROM `$tbl`");
                    if ($res) {
                        while ($row = $res->fetch_assoc()) $rows[] = $row;
                    }
                    $snapshot[$tbl] = $rows;
                    $total_rows    += count($rows);
                }

                // 2. Save the snapshot as a backup record (kept even if nothing was in the tables)
                $label = 'Reset on ' . date('M d, Y g:ia') . ' — ' . implode(', ', $dataset_names);
                $stmt  = $db->prepare("INSERT INTO system_backups (label, tables_included, total_rows, backup_data) VALUES (?,?,?,?)");
                $tables_csv  = implode(',', $tables_to_clear);
                $backup_json = json_encode($snapshot);
                $stmt->bind_param('ssis', $label, $tables_csv, $total_rows, $backup_json);
                $stmt->execute();

                // 3. Now actually clear the tables.
                // PostgreSQL won't let you TRUNCATE `products` while `sales` still has
                // rows pointing at it unless `sales` is cleared too. If the admin only
                // asked to clear Products (not Sales), detach the link first — this
                // mirrors the ON DELETE SET NULL behaviour already defined on the FK,
                // without touching sales history.
                if (in_array('products', $tables_to_clear) && !in_array('sales', $tables_to_clear)) {
                    $db->query("UPDATE sales SET product_id = NULL WHERE product_id IS NOT NULL");
                }
                // Clear child table (sales) before parent table (products) when both are targeted.
                $ordered = $tables_to_clear;
                usort($ordered, function ($a, $b) {
                    if ($a === 'sales' && $b === 'products') return -1;
                    if ($a === 'products' && $b === 'sales') return 1;
                    return 0;
                });
                foreach ($ordered as $tbl) {
                    if (!$db->query("TRUNCATE TABLE $tbl RESTART IDENTITY")) {
                        throw new Exception("Failed clearing table: $tbl");
                    }
                }

                $db->commit();
                $success = 'System data cleared: ' . implode(', ', $dataset_names) . '. A backup was saved — you can restore it anytime from Backup History.';
            } catch (Exception $ex) {
                $db->rollback();
                $error = 'Reset failed — no data was deleted. (' . $ex->getMessage() . ')';
            }
        }
    }
}

// ---- Restore a backup back into the live tables ----
if ($action === 'restore_backup' && isset($_GET['id'])) {
    $id  = intval($_GET['id']);
    $row = $db->query("SELECT * FROM system_backups WHERE id=$id")->fetch_assoc();
    if ($row) {
        $snapshot = json_decode($row['backup_data'], true) ?: [];
        $db->begin_transaction();
        try {
            // Same foreign key issue as the wipe step, in reverse (e.g. restoring a sale
            // whose product row hasn't been re-inserted yet). The sales->products FK is
            // defined as DEFERRABLE, so this defers the check to COMMIT instead of every row.
            $db->query("SET CONSTRAINTS ALL DEFERRED");
            $restored_count = 0;
            $skipped_count  = 0;
            foreach ($snapshot as $tbl => $rows) {
                if (empty($rows)) continue;
                foreach ($rows as $r) {
                    $cols  = array_keys($r);
                    $vals  = array_values($r);
                    $placeholders = implode(',', array_fill(0, count($cols), '?'));
                    $col_list     = '`' . implode('`,`', $cols) . '`';
                    $types = str_repeat('s', count($vals)); // safe generic bind — MySQL casts as needed
                    // INSERT IGNORE: if a row with this same id already exists (e.g. a previous
                    // restore, or new data added since the backup), skip it instead of failing
                    // the whole restore on a primary-key collision.
                    $ins   = $db->prepare("INSERT IGNORE INTO `$tbl` ($col_list) VALUES ($placeholders)");
                    $ins->bind_param($types, ...$vals);
                    $ins->execute();
                    if ($ins->affected_rows > 0) { $restored_count++; } else { $skipped_count++; }
                }
                // Keep future auto-increment ids ahead of whatever was just restored
                if (isset($rows[0]['id'])) {
                    $max_id = max(array_column($rows, 'id'));
                    $db->query("ALTER TABLE `$tbl` AUTO_INCREMENT = " . (intval($max_id) + 1));
                }
            }

            // Remove the backup once restored so it can't accidentally be restored twice
            $db->query("DELETE FROM system_backups WHERE id=$id");
            $db->commit();
            $success = "Backup restored: $restored_count row(s) added";
            if ($skipped_count > 0) {
                $success .= ", $skipped_count skipped (already existed in the live table)";
            }
            $success .= '.';
        } catch (Exception $ex) {
            $db->rollback();
            $error = 'Restore failed: ' . $ex->getMessage();
        }
    } else {
        $error = 'Backup not found.';
    }
}

// ---- Permanently delete a backup ----
if ($action === 'purge_backup' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $db->query("DELETE FROM system_backups WHERE id=$id");
    $success = 'Backup permanently deleted.';
}

// ---- Current live row counts ----
function rowCount($db, $table) {
    $r = $db->query("SELECT COUNT(*) as c FROM `$table`");
    return $r ? intval($r->fetch_assoc()['c']) : 0;
}
$counts = [
    'products' => rowCount($db, 'products'),
    'sales'    => rowCount($db, 'sales'),
    'expenses' => rowCount($db, 'expenses'),
    'deleted_expenses' => rowCount($db, 'deleted_expenses'),
    'budgets'  => rowCount($db, 'category_budgets'),
];

// ---- Backup history list ----
$backups = [];
$bk_res = $db->query("SELECT id, label, tables_included, total_rows, created_at FROM system_backups ORDER BY created_at DESC");
if ($bk_res) { while ($b = $bk_res->fetch_assoc()) $backups[] = $b; }

$db->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Reset - Profit Lens</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            background:
                radial-gradient(circle at 15% 20%, rgba(255,77,77,.07), transparent 40%),
                radial-gradient(circle at 85% 15%, rgba(45,122,79,.06), transparent 45%),
                radial-gradient(circle at 75% 85%, rgba(255,183,77,.08), transparent 40%),
                linear-gradient(160deg, #fef9f9 0%, #f6f8f7 45%, #fdf7ef 100%);
            background-attachment: fixed;
            min-height: 100vh;
        }
        .page-content { position:relative; overflow:hidden; }
        .page-content::before,
        .page-content::after {
            content:''; position:absolute; border-radius:50%;
            filter: blur(60px); z-index:0; pointer-events:none;
        }
        .page-content::before {
            width:320px; height:320px; top:-120px; right:-80px;
            background: radial-gradient(circle, rgba(220,53,69,.10), transparent 70%);
        }
        .page-content::after {
            width:280px; height:280px; bottom:-100px; left:10%;
            background: radial-gradient(circle, rgba(45,122,79,.10), transparent 70%);
        }
        .danger-card { position:relative; z-index:1; backdrop-filter: blur(2px); }

        .danger-card {
            background:#fff; border-radius:14px; border:2px solid #ffd6d6;
            box-shadow:0 2px 10px rgba(220,53,69,.08);
            padding:26px 28px; max-width:680px;
        }
        .danger-card-header { display:flex; align-items:flex-start; gap:12px; margin-bottom:6px; flex-wrap:wrap; }
        .danger-card-header .icon {
            width:44px; height:44px; border-radius:50%; background:#fff0f0;
            border:2px solid #ffd6d6; display:flex; align-items:center;
            justify-content:center; font-size:22px; flex-shrink:0;
        }
        .danger-card h2 { font-size:18px; font-weight:800; color:#1a1a2e; margin:0; }
        .danger-card .subtitle { font-size:12.5px; color:#999; margin:2px 0 0; }
        .btn-backup-history {
            margin-left:auto; display:inline-flex; align-items:center; gap:5px;
            padding:7px 13px; border-radius:8px; border:1.5px solid #e0e0e0;
            background:#fff; font-size:11.5px; font-weight:700; color:#555;
            cursor:pointer; font-family:inherit; transition:all .15s; white-space:nowrap;
        }
        .btn-backup-history:hover { border-color:#6c5ce7; color:#6c5ce7; background:#f7f5ff; }

        .danger-warning-banner {
            background:#fff8e1; border:1px solid #ffe0a3; border-radius:10px;
            padding:12px 16px; font-size:12.5px; color:#8a6d1a; margin:18px 0 20px; line-height:1.6;
        }
        .reset-options { display:flex; flex-direction:column; gap:10px; margin-bottom:22px; }
        .reset-option {
            display:flex; align-items:center; justify-content:space-between; gap:12px;
            border:1.5px solid #eee; border-radius:10px; padding:12px 16px;
            transition:border-color .15s, background .15s; cursor:pointer;
        }
        .reset-option:hover { border-color:#ffb3b3; background:#fffafa; }
        .reset-option input[type="checkbox"] { width:17px; height:17px; accent-color:#dc3545; cursor:pointer; }
        .reset-option-label { display:flex; align-items:center; gap:10px; }
        .reset-option-name { font-size:13.5px; font-weight:700; color:#333; }
        .reset-option-count {
            font-size:11px; font-weight:700; color:#dc3545; background:#fde8e8;
            padding:2px 9px; border-radius:12px; white-space:nowrap;
        }
        .reset-option-count.zero { color:#888; background:#f2f2f2; }
        .btn-open-reset {
            width:100%; padding:14px 0; border-radius:10px; border:none;
            background:linear-gradient(135deg,#ff4d4d,#dc3545); color:#fff;
            font-size:14px; font-weight:800; cursor:pointer;
            box-shadow:0 4px 14px rgba(220,53,69,.3); transition:transform .15s, box-shadow .15s;
        }
        .btn-open-reset:hover { transform:translateY(-1px); box-shadow:0 6px 20px rgba(220,53,69,.4); }
        .btn-open-reset:disabled {
            background:#e0e0e0; color:#aaa; cursor:not-allowed; box-shadow:none; transform:none;
        }

        /* Shared modal look */
        .rs-modal-overlay {
            display:none; position:fixed; inset:0; background:rgba(0,0,0,.5);
            backdrop-filter:blur(3px); z-index:9999; align-items:center; justify-content:center;
        }
        .rs-modal-overlay.active { display:flex; animation:fadeOverlay .2s ease; }
        .rs-modal {
            background:#fff; border-radius:18px; box-shadow:0 20px 60px rgba(0,0,0,.2);
            padding:32px 30px 26px; width:100%; max-width:420px;
            animation:popIn .25s cubic-bezier(.34,1.56,.64,1);
        }
        .rs-modal-icon {
            width:60px; height:60px; background:#fff0f0; border-radius:50%;
            display:flex; align-items:center; justify-content:center; font-size:28px;
            margin:0 auto 16px; border:3px solid #ffb3b3;
        }
        .rs-modal h3 { text-align:center; font-size:18px; font-weight:800; color:#1a1a2e; margin:0 0 8px; }
        .rs-modal p  { text-align:center; font-size:12.5px; color:#888; line-height:1.6; margin:0 0 16px; }
        .rs-modal-list {
            background:#fafafa; border-radius:10px; padding:12px 16px; margin-bottom:18px;
            font-size:12.5px; color:#555;
        }
        .rs-modal-list li { margin-bottom:3px; }
        .reset-confirm-label { font-size:12px; font-weight:700; color:#444; display:block; margin-bottom:6px; }
        .reset-confirm-input {
            width:100%; padding:11px 14px; border-radius:9px; border:1.5px solid #e0e0e0;
            font-size:14px; font-family:inherit; text-align:center; letter-spacing:1px;
            font-weight:700; outline:none; margin-bottom:18px; box-sizing:border-box;
        }
        .reset-confirm-input:focus { border-color:#dc3545; }
        .rs-modal-actions { display:flex; gap:10px; }
        .btn-modal-cancel {
            flex:1; padding:12px 0; border-radius:10px; border:1.5px solid #e0e0e0;
            background:#f7f7f7; color:#555; font-size:13px; font-weight:700;
            cursor:pointer; font-family:inherit; transition:background .18s;
        }
        .btn-modal-cancel:hover { background:#ebebeb; }
        .btn-modal-confirm-delete {
            flex:1; padding:12px 0; border-radius:10px; border:none;
            background:#e0e0e0; color:#aaa; font-size:13px; font-weight:700;
            cursor:not-allowed; font-family:inherit; transition:all .18s;
        }
        .btn-modal-confirm-delete.enabled {
            background:linear-gradient(135deg,#ff4d4d,#dc3545); color:#fff; cursor:pointer;
            box-shadow:0 4px 14px rgba(220,53,69,.35);
        }
        .btn-modal-confirm-delete.enabled:hover { transform:translateY(-1px); }

        /* Backup History modal */
        .backup-history-modal { max-width:720px; text-align:left; padding:28px 26px 24px; }
        .backup-history-modal h3 { text-align:center; }
        .bh-icon { background:#f3f0ff; border-color:#dcd3ff; }
        .bh-table-wrap { max-height:380px; overflow-y:auto; border:1px solid #f0f0f0; border-radius:10px; margin-top:14px; }
        .bh-table-wrap table { width:100%; border-collapse:collapse; }
        .bh-table-wrap th, .bh-table-wrap td { padding:10px 12px; font-size:12px; text-align:left; border-bottom:1px solid #f2f2f2; }
        .bh-table-wrap thead th { position:sticky; top:0; background:#f8f9fa; font-size:11px; color:#888; z-index:1; }
        .bh-empty { text-align:center; color:#aaa; padding:26px; font-size:12.5px; }
        .btn-restore {
            display:inline-block; padding:5px 10px; font-size:10.5px; font-weight:700;
            border-radius:6px; border:1.5px solid #2d7a4f; color:#2d7a4f;
            background:#f0faf4; text-decoration:none; cursor:pointer; margin-right:6px;
        }
        .btn-restore:hover { background:#2d7a4f; color:#fff; }
        .btn-purge {
            display:inline-block; padding:5px 10px; font-size:10.5px; font-weight:700;
            border-radius:6px; border:1.5px solid #dc3545; color:#dc3545;
            background:#fff5f5; text-decoration:none; cursor:pointer;
        }
        .btn-purge:hover { background:#dc3545; color:#fff; }
        .bh-tables-pill {
            font-size:10px; font-weight:700; padding:2px 8px; border-radius:10px;
            background:#eef2ff; color:#4338ca; display:inline-block; margin:2px 3px 2px 0;
        }

        @keyframes fadeOverlay { from{opacity:0} to{opacity:1} }
        @keyframes popIn { from{opacity:0;transform:scale(.88)} to{opacity:1;transform:scale(1)} }
    </style>
</head>
<body>

<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <div class="topbar-title"><p>Admin Tools</p><h1>System Reset</h1></div>
            <div class="topbar-user">
                <div class="topbar-avatar">👤</div>
                <span class="admin-badge">🔐 Admin</span>
            </div>
        </div>

        <div class="page-content">
            <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
            <?php if ($error):   ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <div class="danger-card">
                <div class="danger-card-header">
                    <div class="icon">⚠️</div>
                    <div>
                        <h2>Danger Zone — Clear System Data</h2>
                        <p class="subtitle">Use this before going live to wipe test/demo records.</p>
                    </div>
                    <button type="button" class="btn-backup-history" onclick="openBackupHistoryModal()">
                        🗄️ Backup History <?= count($backups) ? '(' . count($backups) . ')' : '' ?>
                    </button>
                </div>

                <div class="danger-warning-banner">
                    ✅ Every reset automatically saves a full backup first — nothing is ever lost
                    permanently unless you purge a backup from Backup History yourself.
                </div>

                <form id="resetForm" method="POST">
                    <div class="reset-options">
                        <label class="reset-option">
                            <div class="reset-option-label">
                                <input type="checkbox" name="targets[]" value="products">
                                <span class="reset-option-name">📦 Products</span>
                            </div>
                            <span class="reset-option-count <?= $counts['products'] == 0 ? 'zero' : '' ?>"><?= $counts['products'] ?> records</span>
                        </label>

                        <label class="reset-option">
                            <div class="reset-option-label">
                                <input type="checkbox" name="targets[]" value="sales">
                                <span class="reset-option-name">🧾 Sales Records</span>
                            </div>
                            <span class="reset-option-count <?= $counts['sales'] == 0 ? 'zero' : '' ?>"><?= $counts['sales'] ?> records</span>
                        </label>

                        <label class="reset-option">
                            <div class="reset-option-label">
                                <input type="checkbox" name="targets[]" value="expenses">
                                <span class="reset-option-name">💸 Expenses &amp; Deleted History</span>
                            </div>
                            <span class="reset-option-count <?= ($counts['expenses'] + $counts['deleted_expenses']) == 0 ? 'zero' : '' ?>">
                                <?= $counts['expenses'] + $counts['deleted_expenses'] ?> records
                            </span>
                        </label>

                        <label class="reset-option">
                            <div class="reset-option-label">
                                <input type="checkbox" name="targets[]" value="budgets">
                                <span class="reset-option-name">⚙️ Category Budget Settings</span>
                            </div>
                            <span class="reset-option-count <?= $counts['budgets'] == 0 ? 'zero' : '' ?>"><?= $counts['budgets'] ?> set</span>
                        </label>
                    </div>

                    <button type="button" class="btn-open-reset" id="openResetBtn" disabled onclick="openResetModal()">
                        Clear Selected Data
                    </button>

                    <input type="hidden" name="confirm_reset" value="1">
                    <input type="hidden" name="confirm_text" id="confirmTextHidden" value="">
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Reset Confirmation Modal -->
<div class="rs-modal-overlay" id="resetModalOverlay">
    <div class="rs-modal">
        <div class="rs-modal-icon">🔥</div>
        <h3>Are you sure?</h3>
        <p>The tables below will be cleared. A backup will be saved automatically before anything is deleted, so you can restore it later from Backup History.</p>
        <ul class="rs-modal-list" id="resetModalList"></ul>

        <label class="reset-confirm-label">Type <strong>RESET</strong> to confirm:</label>
        <input type="text" class="reset-confirm-input" id="resetConfirmInput" placeholder="RESET" autocomplete="off">

        <div class="rs-modal-actions">
            <button type="button" class="btn-modal-cancel" onclick="closeResetModal()">Cancel</button>
            <button type="button" class="btn-modal-confirm-delete" id="confirmDeleteBtn" onclick="submitReset()">Yes, Clear Data</button>
        </div>
    </div>
</div>

<!-- Backup History Modal -->
<div class="rs-modal-overlay" id="backupHistoryModalOverlay">
    <div class="rs-modal backup-history-modal">
        <h3>🗄️ Backup History</h3>
        <p class="modal-sub" style="text-align:center;font-size:12.5px;color:#888;">
            Every System Reset automatically saves a snapshot here first. Restore brings the data back into your live tables; Purge removes the backup forever.
        </p>

        <?php if (empty($backups)): ?>
        <div class="bh-empty">No backups yet — nothing has been cleared through System Reset.</div>
        <?php else: ?>
        <div class="bh-table-wrap">
            <table>
                <thead>
                    <tr><th>Created</th><th>Contents</th><th>Rows</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $b):
                        $tables = explode(',', $b['tables_included']);
                    ?>
                    <tr>
                        <td style="white-space:nowrap;"><?= date('M d, Y g:ia', strtotime($b['created_at'])) ?></td>
                        <td>
                            <?php foreach ($tables as $t): ?>
                            <span class="bh-tables-pill"><?= htmlspecialchars(trim($t)) ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td style="font-weight:700;"><?= intval($b['total_rows']) ?></td>
                        <td style="white-space:nowrap;">
                            <a href="#" class="btn-restore"
                               onclick="openRestoreConfirmModal(<?= $b['id'] ?>, '<?= date('M d, Y g:ia', strtotime($b['created_at'])) ?>', <?= intval($b['total_rows']) ?>); return false;">Restore</a>
                            <a href="#" class="btn-purge"
                               onclick="openPurgeBackupModal(<?= $b['id'] ?>, '<?= date('M d, Y g:ia', strtotime($b['created_at'])) ?>'); return false;">Purge</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <div class="rs-modal-actions" style="margin-top:18px;">
            <button type="button" class="btn-modal-cancel" onclick="closeBackupHistoryModal()" style="flex:none;padding:12px 28px;margin:0 auto;">Close</button>
        </div>
    </div>
</div>

<!-- Restore Confirmation Modal (kept above Backup History in z-order so it renders on top) -->
<div class="rs-modal-overlay" id="restoreConfirmModalOverlay" style="z-index:10000;">
    <div class="rs-modal">
        <div class="rs-modal-icon" style="background:#f0faf4;border-color:#a8d5b0;">♻️</div>
        <h3>Restore This Backup?</h3>
        <p>
            Backup from <strong id="restoreModalDate">—</strong>
            (<span id="restoreModalRows">—</span> rows) will be added back into the live tables.
            Current data in those tables will remain — restored rows are added alongside it, not replacing it.
        </p>
        <div class="rs-modal-actions">
            <button type="button" class="btn-modal-cancel" onclick="closeRestoreConfirmModal()">Cancel</button>
            <a id="restoreConfirmBtn" href="#" class="btn-modal-confirm-delete enabled" style="text-decoration:none;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#2d9d63,#2d7a4f);box-shadow:0 4px 14px rgba(45,122,79,.35);">Yes, Restore</a>
        </div>
    </div>
</div>

<!-- Purge Backup Confirmation Modal -->
<div class="rs-modal-overlay" id="purgeBackupModalOverlay" style="z-index:10000;">
    <div class="rs-modal">
        <div class="rs-modal-icon">🔥</div>
        <h3>Delete This Backup Permanently?</h3>
        <p>
            Backup from <strong id="purgeBackupModalDate">—</strong> will be deleted.
            This cannot be undone — once purged, this data can never be restored.
        </p>
        <div class="rs-modal-actions">
            <button type="button" class="btn-modal-cancel" onclick="closePurgeBackupModal()">Cancel</button>
            <a id="purgeBackupConfirmBtn" href="#" class="btn-modal-confirm-delete enabled" style="text-decoration:none;display:flex;align-items:center;justify-content:center;">Yes, Delete Forever</a>
        </div>
    </div>
</div>

<script>
const labels = {
    products: '📦 All Products',
    sales:    '🧾 All Sales Records',
    expenses: '💸 All Expenses & Deleted Expense History',
    budgets:  '⚙️ Category Budget Settings'
};

const checkboxes  = document.querySelectorAll('input[name="targets[]"]');
const openBtn     = document.getElementById('openResetBtn');
const modalList    = document.getElementById('resetModalList');
const confirmInput = document.getElementById('resetConfirmInput');
const confirmBtn   = document.getElementById('confirmDeleteBtn');

function updateOpenBtnState() {
    const anyChecked = Array.from(checkboxes).some(cb => cb.checked);
    openBtn.disabled = !anyChecked;
}
checkboxes.forEach(cb => cb.addEventListener('change', updateOpenBtnState));

function openResetModal() {
    modalList.innerHTML = '';
    checkboxes.forEach(cb => {
        if (cb.checked) {
            const li = document.createElement('li');
            li.textContent = labels[cb.value] || cb.value;
            modalList.appendChild(li);
        }
    });
    confirmInput.value = '';
    confirmBtn.classList.remove('enabled');
    document.getElementById('resetModalOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeResetModal() {
    document.getElementById('resetModalOverlay').classList.remove('active');
    document.body.style.overflow = '';
}
document.getElementById('resetModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeResetModal();
});

confirmInput.addEventListener('input', function() {
    confirmBtn.classList.toggle('enabled', this.value.trim() === 'RESET');
});

function submitReset() {
    if (confirmInput.value.trim() !== 'RESET') return;
    document.getElementById('confirmTextHidden').value = 'RESET';
    document.getElementById('resetForm').submit();
}

function openBackupHistoryModal() {
    document.getElementById('backupHistoryModalOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeBackupHistoryModal() {
    document.getElementById('backupHistoryModalOverlay').classList.remove('active');
    document.body.style.overflow = '';
}
document.getElementById('backupHistoryModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeBackupHistoryModal();
});

function openRestoreConfirmModal(id, dateStr, rows) {
    document.getElementById('restoreModalDate').textContent = dateStr || '—';
    document.getElementById('restoreModalRows').textContent = rows;
    document.getElementById('restoreConfirmBtn').href = 'system_reset.php?action=restore_backup&id=' + id;
    document.getElementById('restoreConfirmModalOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeRestoreConfirmModal() {
    document.getElementById('restoreConfirmModalOverlay').classList.remove('active');
    document.body.style.overflow = '';
}
document.getElementById('restoreConfirmModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeRestoreConfirmModal();
});

function openPurgeBackupModal(id, dateStr) {
    document.getElementById('purgeBackupModalDate').textContent = dateStr || '—';
    document.getElementById('purgeBackupConfirmBtn').href = 'system_reset.php?action=purge_backup&id=' + id;
    document.getElementById('purgeBackupModalOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closePurgeBackupModal() {
    document.getElementById('purgeBackupModalOverlay').classList.remove('active');
    document.body.style.overflow = '';
}
document.getElementById('purgeBackupModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closePurgeBackupModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeResetModal();
        closeBackupHistoryModal();
        closeRestoreConfirmModal();
        closePurgeBackupModal();
    }
});
</script>
</body>
</html>