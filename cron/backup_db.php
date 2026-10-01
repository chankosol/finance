<?php
// C:\xampp\htdocs\finance\cron\backup_db.php
declare(strict_types=1);

// Set execution time limit to 5 minutes for large databases
set_time_limit(300);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
global $pdo;

$backupDir = __DIR__ . '/../backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

$date = date('d_M_Y');
$dbName = FIN_DB_NAME;
$backupFile = $backupDir . "/backup_finance_{$date}.sql";

echo "Starting database backup for '{$dbName}'...\n";

try {
    $handle = fopen($backupFile, 'w');
    if (!$handle) {
        throw new RuntimeException("Cannot create backup file: {$backupFile}");
    }

    // Write database header info
    fwrite($handle, "-- Database Backup for '{$dbName}'\n");
    fwrite($handle, "-- Generated on: " . date('Y-m-d H:i:s') . "\n");
    fwrite($handle, "-- Timezone: " . date_default_timezone_get() . "\n\n");
    fwrite($handle, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

    // Get all tables
    $tablesQuery = $pdo->query("SHOW TABLES");
    $tables = $tablesQuery->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        echo "Exporting table '{$table}'...\n";
        
        fwrite($handle, "-- -----------------------------------------------------\n");
        fwrite($handle, "-- Table structure for table `{$table}`\n");
        fwrite($handle, "-- -----------------------------------------------------\n");
        fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
        
        // Get Create Table schema
        $createTableQuery = $pdo->query("SHOW CREATE TABLE `{$table}`");
        $createTableRow = $createTableQuery->fetch(PDO::FETCH_NUM);
        fwrite($handle, $createTableRow[1] . ";\n\n");

        // Get table data
        fwrite($handle, "-- Dumping data for table `{$table}`\n");
        $dataQuery = $pdo->query("SELECT * FROM `{$table}`");
        $rows = $dataQuery->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($rows)) {
            // Write inserts in chunks of 100 rows to prevent long query lines
            $chunkSize = 100;
            $chunks = array_chunk($rows, $chunkSize);
            
            foreach ($chunks as $chunk) {
                $insertQuery = "INSERT INTO `{$table}` (";
                $columns = array_keys($chunk[0]);
                $insertQuery .= implode(", ", array_map(fn($col) => "`{$col}`", $columns));
                $insertQuery .= ") VALUES\n";
                
                $valuesList = [];
                foreach ($chunk as $row) {
                    $rowValues = [];
                    foreach ($row as $val) {
                        if ($val === null) {
                            $rowValues[] = "NULL";
                        } else {
                            $rowValues[] = $pdo->quote((string)$val);
                        }
                    }
                    $valuesList[] = "(" . implode(", ", $rowValues) . ")";
                }
                
                $insertQuery .= implode(",\n", $valuesList) . ";\n";
                fwrite($handle, $insertQuery . "\n");
            }
        }
        fwrite($handle, "\n");
    }

    fwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");
    fclose($handle);
    
    echo "Backup file successfully created: " . basename($backupFile) . "\n";
    
    // Clean up old backups (keep last 30 days)
    echo "Cleaning up backups older than 30 days...\n";
    $files = glob($backupDir . "/backup_finance_*.sql");
    $now = time();
    $days30 = 30 * 24 * 60 * 60;
    
    $deletedCount = 0;
    foreach ($files as $file) {
        if (is_file($file)) {
            if ($now - filemtime($file) > $days30) {
                unlink($file);
                $deletedCount++;
            }
        }
    }
    echo "Cleaned up {$deletedCount} old backup files.\n";
    echo "Backup process finished successfully!\n";

} catch (Exception $e) {
    echo "Backup failed: " . $e->getMessage() . "\n";
    if (isset($handle) && is_resource($handle)) {
        fclose($handle);
    }
    if (is_file($backupFile)) {
        unlink($backupFile);
    }
    exit(1);
}
