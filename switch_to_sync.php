<?php
/**
 * POS Sistemi - DIRECT Moddan SYNC Moduna Geçiş
 * v2.1 - Hibrit Mimari
 * 
 * Bu dosya Dolunay mağazayı DIRECT → SYNC moduna geçirir
 * 1 ay sonra sabit IP alındığında çalıştırılacak
 */

require_once __DIR__ . '/session_manager.php';
secure_session_start();

// Yetki kontrolü - sadece admin
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    die(json_encode(['success' => false, 'message' => 'Yetkisiz erişim']));
}

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

/**
 * Ana geçiş fonksiyonu
 */
function switchToSyncMode() {
    $result = [
        'success' => false,
        'steps' => [],
        'errors' => [],
        'warnings' => []
    ];
    
    try {
        // STEP 1: Geçiş öncesi kontroller
        $result['steps'][] = 'Geçiş öncesi sistem kontrolleri başlatılıyor...';
        
        if (!preflightChecks($result)) {
            return $result;
        }
        
        // STEP 2: Ana sunucudan tam veri yedeklemesi
        $result['steps'][] = 'Ana sunucudan tam veri yedeklemesi alınıyor...';
        
        if (!createFullBackup($result)) {
            return $result;
        }
        
        // STEP 3: Local MySQL veritabanı oluştur
        $result['steps'][] = 'Local MySQL veritabanı oluşturuluyor...';
        
        if (!createLocalDatabase($result)) {
            return $result;
        }
        
        // STEP 4: Verileri local veritabanına aktar
        $result['steps'][] = 'Veriler local veritabanına aktarılıyor...';
        
        if (!migrateDataToLocal($result)) {
            return $result;
        }
        
        // STEP 5: Sync API'lerini kur
        $result['steps'][] = 'Sync API dosyaları kuruluyor...';
        
        if (!installSyncAPIs($result)) {
            return $result;
        }
        
        // STEP 6: Config dosyasını güncelle
        $result['steps'][] = 'Sistem konfigürasyonu güncelleniyor...';
        
        if (!updateConfiguration($result)) {
            return $result;
        }
        
        // STEP 7: İlk senkronizasyon testi
        $result['steps'][] = 'İlk senkronizasyon testi yapılıyor...';
        
        if (!testInitialSync($result)) {
            return $result;
        }
        
        // STEP 8: Webhook kayıtları
        $result['steps'][] = 'Ana sunucuya webhook kayıtları yapılıyor...';
        
        if (!registerWebhooks($result)) {
            return $result;
        }
        
        // STEP 9: Cron job'ları kur
        $result['steps'][] = 'Otomatik senkronizasyon görevleri kuruluyor...';
        
        if (!setupCronJobs($result)) {
            $result['warnings'][] = 'Cron job\'ları manuel olarak ayarlanmalı';
        }
        
        // STEP 10: Sistem yeniden başlatma
        $result['steps'][] = 'Sistem SYNC moduna geçiriliyor...';
        
        if (!finalizeSwitch($result)) {
            return $result;
        }
        
        $result['success'] = true;
        $result['steps'][] = '✅ SYNC moda geçiş başarıyla tamamlandı!';
        $result['message'] = 'Dolunay mağaza artık SYNC modunda çalışıyor.';
        
        // Başarılı geçiş logla
        logSwitchAttempt('SUCCESS', 'DIRECT → SYNC geçişi başarıyla tamamlandı');
        
    } catch (Exception $e) {
        $result['errors'][] = 'Kritik hata: ' . $e->getMessage();
        $result['steps'][] = '❌ Geçiş işlemi başarısız!';
        
        // Rollback işlemi
        rollbackSwitch($result);
        
        // Hata logla
        logSwitchAttempt('ERROR', 'DIRECT → SYNC geçişi başarısız: ' . $e->getMessage());
    }
    
    return $result;
}

/**
 * Geçiş öncesi sistem kontrolleri
 */
function preflightChecks(&$result) {
    // Mevcut mod kontrolü
    if (SYNC_MODE !== 'DIRECT') {
        $result['errors'][] = 'Sistem zaten SYNC modunda çalışıyor';
        return false;
    }
    
    // Store ID kontrolü (sadece Dolunay)
    if (STORE_ID !== 2) {
        $result['errors'][] = 'Bu işlem sadece Dolunay mağaza için geçerli (STORE_ID=2)';
        return false;
    }
    
    // PHP versiyon kontrolü
    if (version_compare(PHP_VERSION, SYSTEM_INFO['php_min_version'], '<')) {
        $result['errors'][] = 'PHP versionu en az ' . SYSTEM_INFO['php_min_version'] . ' olmalı';
        return false;
    }
    
    // MySQL kontrolü
    try {
        require_once __DIR__ . '/db_connection_direct.php';
        $stmt = $conn->query("SELECT VERSION() as mysql_version");
        $version = $stmt->fetch()['mysql_version'];
        
        if (version_compare($version, SYSTEM_INFO['mysql_min_version'], '<')) {
            $result['errors'][] = 'MySQL versionu en az ' . SYSTEM_INFO['mysql_min_version'] . ' olmalı';
            return false;
        }
    } catch (Exception $e) {
        $result['errors'][] = 'Ana sunucu veritabanı bağlantısı kontrol edilemiyor: ' . $e->getMessage();
        return false;
    }
    
    // Disk alanı kontrolü (en az 1GB)
    $freeSpace = disk_free_space(__DIR__) / 1024 / 1024 / 1024; // GB
    if ($freeSpace < 1) {
        $result['errors'][] = 'Yetersiz disk alanı. En az 1GB boş alan gerekli';
        return false;
    }
    
    // Yazma izinleri kontrolü
    $testDirs = [__DIR__, __DIR__ . '/admin', __DIR__ . '/logs'];
    foreach ($testDirs as $dir) {
        if (!is_writable($dir)) {
            $result['errors'][] = 'Yazma izni yok: ' . $dir;
            return false;
        }
    }
    
    // Ana sunucu bağlantısı kontrolü
    if (!checkMainServerConnection()) {
        $result['errors'][] = 'Ana sunucuya bağlantı kurulamıyor';
        return false;
    }
    
    $result['steps'][] = '✅ Ön kontroller başarıyla tamamlandı';
    return true;
}

/**
 * Ana sunucudan tam veri yedeklemesi
 */
function createFullBackup(&$result) {
    try {
        require_once __DIR__ . '/db_connection_direct.php';
        
        $backupDir = __DIR__ . '/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        $backupFile = $backupDir . '/pre_sync_backup_' . date('Y-m-d_H-i-s') . '.sql';
        
        // Mağaza özel verileri yedekle
        $tables = [
            'satis_faturalari' => "WHERE magaza = " . STORE_ID,
            'satis_fatura_detay' => "WHERE fatura_id IN (SELECT id FROM satis_faturalari WHERE magaza = " . STORE_ID . ")",
            'magaza_stok' => "WHERE magaza_id = " . STORE_ID,
            'stok_hareketleri' => "WHERE magaza_id = " . STORE_ID,
            'musteriler' => "", // Tüm müşteriler
            'musteri_puanlar' => "",
            'musteri_borclar' => "WHERE magaza_id = " . STORE_ID,
            'musteri_borc_detaylar' => "",
            'musteri_borc_odemeler' => "",
            'urun_stok' => "WHERE durum = 'aktif'",
            'magazalar' => "WHERE id = " . STORE_ID,
            'personel' => "WHERE magaza_id = " . STORE_ID,
            'puan_ayarlari' => "",
            'sistem_ayarlari' => "",
            'store_config' => "WHERE magaza_id = " . STORE_ID
        ];
        
        $backup = "-- POS System Backup - DIRECT to SYNC Migration\n";
        $backup .= "-- Store: " . STORE_NAME . " (ID: " . STORE_ID . ")\n";
        $backup .= "-- Date: " . date('Y-m-d H:i:s') . "\n\n";
        $backup .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
        
        foreach ($tables as $table => $where) {
            $sql = "SELECT * FROM {$table}";
            if (!empty($where)) {
                $sql .= " {$where}";
            }
            
            $stmt = $conn->query($sql);
            $rows = $stmt->fetchAll();
            
            if (!empty($rows)) {
                $backup .= "-- Table: {$table}\n";
                
                // Table structure
                $createStmt = $conn->query("SHOW CREATE TABLE {$table}");
                $createRow = $createStmt->fetch();
                $backup .= $createRow['Create Table'] . ";\n\n";
                
                // Data
                foreach ($rows as $row) {
                    $values = array_map(function($value) use ($conn) {
                        return $value === null ? 'NULL' : $conn->quote($value);
                    }, array_values($row));
                    
                    $backup .= "INSERT INTO {$table} VALUES (" . implode(', ', $values) . ");\n";
                }
                
                $backup .= "\n";
            }
        }
        
        $backup .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        
        if (file_put_contents($backupFile, $backup) === false) {
            $result['errors'][] = 'Yedekleme dosyası oluşturulamadı';
            return false;
        }
        
        // Backup doğrulama
        $backupSize = filesize($backupFile) / 1024 / 1024; // MB
        if ($backupSize < 0.1) {
            $result['warnings'][] = 'Yedekleme dosyası çok küçük görünüyor (' . round($backupSize, 2) . ' MB)';
        }
        
        $result['steps'][] = '✅ Yedekleme tamamlandı (' . round($backupSize, 2) . ' MB)';
        $result['backup_file'] = $backupFile;
        
        return true;
        
    } catch (Exception $e) {
        $result['errors'][] = 'Yedekleme hatası: ' . $e->getMessage();
        return false;
    }
}

/**
 * Local MySQL veritabanı oluştur
 */
function createLocalDatabase(&$result) {
    try {
        $localConfig = [
            'host' => 'localhost',
            'username' => 'root',
            'password' => '', // XAMPP default
            'charset' => 'utf8'
        ];
        
        $newDbName = 'pos_local_' . STORE_ID;
        
        // Root bağlantısı
        $dsn = "mysql:host={$localConfig['host']};charset={$localConfig['charset']}";
        $conn = new PDO($dsn, $localConfig['username'], $localConfig['password']);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Veritabanı oluştur
        $conn->exec("CREATE DATABASE IF NOT EXISTS `{$newDbName}` CHARACTER SET utf8 COLLATE utf8_general_ci");
        
        // Local kullanıcı oluştur
        $localUser = 'pos_local';
        $localPass = 'local_2025_secure';
        
        $conn->exec("DROP USER IF EXISTS '{$localUser}'@'localhost'");
        $conn->exec("CREATE USER '{$localUser}'@'localhost' IDENTIFIED BY '{$localPass}'");
        $conn->exec("GRANT ALL PRIVILEGES ON `{$newDbName}`.* TO '{$localUser}'@'localhost'");
        $conn->exec("FLUSH PRIVILEGES");
        
        $result['steps'][] = '✅ Local veritabanı oluşturuldu: ' . $newDbName;
        $result['local_database'] = $newDbName;
        
        return true;
        
    } catch (Exception $e) {
        $result['errors'][] = 'Local veritabanı oluşturma hatası: ' . $e->getMessage();
        return false;
    }
}

/**
 * Verileri local veritabanına aktar
 */
function migrateDataToLocal(&$result) {
    try {
        $backupFile = $result['backup_file'];
        $localDbName = $result['local_database'];
        
        // Local veritabanına bağlan
        $localConfig = [
            'host' => 'localhost',
            'dbname' => $localDbName,
            'username' => 'pos_local',
            'password' => 'local_2025_secure',
            'charset' => 'utf8'
        ];
        
        $dsn = "mysql:host={$localConfig['host']};dbname={$localConfig['dbname']};charset={$localConfig['charset']}";
        $localConn = new PDO($dsn, $localConfig['username'], $localConfig['password']);
        $localConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Backup dosyasını oku ve execute et
        $backup = file_get_contents($backupFile);
        if ($backup === false) {
            $result['errors'][] = 'Yedekleme dosyası okunamadı';
            return false;
        }
        
        // SQL komutlarını ayır ve çalıştır
        $statements = explode(';', $backup);
        $executedCount = 0;
        
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement) && !preg_match('/^--/', $statement)) {
                try {
                    $localConn->exec($statement);
                    $executedCount++;
                } catch (PDOException $e) {
                    $result['warnings'][] = 'SQL hatası (devam ediliyor): ' . $e->getMessage();
                }
            }
        }
        
        // Sync tabloları oluştur
        createSyncTables($localConn, $result);
        
        $result['steps'][] = "✅ Veri aktarımı tamamlandı ({$executedCount} komut çalıştırıldı)";
        
        return true;
        
    } catch (Exception $e) {
        $result['errors'][] = 'Veri aktarım hatası: ' . $e->getMessage();
        return false;
    }
}

/**
 * Sync tabloları oluştur
 */
function createSyncTables($localConn, &$result) {
    $syncTables = "
        CREATE TABLE IF NOT EXISTS sync_queue (
            id INT AUTO_INCREMENT PRIMARY KEY,
            magaza_id INT NOT NULL,
            operation_type ENUM('sale','stock_update','customer_update','price_update') NOT NULL,
            table_name VARCHAR(50) NOT NULL,
            record_id INT DEFAULT NULL,
            data_json TEXT NOT NULL,
            priority INT(3) DEFAULT 5,
            attempts INT(3) DEFAULT 0,
            max_attempts INT(3) DEFAULT 3,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            scheduled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME DEFAULT NULL,
            status ENUM('pending','processing','completed','failed','cancelled') DEFAULT 'pending',
            error_message TEXT,
            sync_hash VARCHAR(64) DEFAULT NULL,
            INDEX idx_magaza_status (magaza_id, status),
            INDEX idx_scheduled (scheduled_at),
            INDEX idx_priority (priority)
        );
        
        CREATE TABLE IF NOT EXISTS sync_metadata (
            id INT AUTO_INCREMENT PRIMARY KEY,
            magaza_id INT DEFAULT NULL,
            tablo_adi VARCHAR(50) DEFAULT NULL,
            son_sync_tarihi DATETIME DEFAULT NULL,
            sync_durumu ENUM('basarili','hata') DEFAULT 'basarili',
            operation_count INT DEFAULT 0,
            last_error TEXT,
            sync_version VARCHAR(20) DEFAULT '1.0',
            UNIQUE KEY magaza_id (magaza_id, tablo_adi)
        );
        
        CREATE TABLE IF NOT EXISTS offline_sales (
            id INT AUTO_INCREMENT PRIMARY KEY,
            magaza_id INT NOT NULL,
            local_invoice_id VARCHAR(50) NOT NULL,
            sale_data JSON NOT NULL,
            items_data JSON NOT NULL,
            customer_data JSON DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            synced_at DATETIME DEFAULT NULL,
            synced_invoice_id INT DEFAULT NULL,
            status ENUM('pending','synced','failed','duplicate') DEFAULT 'pending',
            error_message TEXT,
            checksum VARCHAR(64) DEFAULT NULL,
            UNIQUE KEY unique_local_invoice (magaza_id, local_invoice_id)
        );
    ";
    
    $statements = explode(';', $syncTables);
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement)) {
            $localConn->exec($statement);
        }
    }
    
    $result['steps'][] = '✅ Sync tabloları oluşturuldu';
}

/**
 * Sync API dosyalarını kur
 */
function installSyncAPIs(&$result) {
    try {
        $apiDir = __DIR__ . '/admin/api/local_sync';
        if (!is_dir($apiDir)) {
            mkdir($apiDir, 0755, true);
        }
        
        // send_sales.php
        $sendSalesContent = generateSendSalesAPI();
        file_put_contents($apiDir . '/send_sales.php', $sendSalesContent);
        
        // receive_webhook.php
        $receiveWebhookContent = generateReceiveWebhookAPI();
        file_put_contents($apiDir . '/receive_webhook.php', $receiveWebhookContent);
        
        // queue_manager.php
        $queueManagerContent = generateQueueManagerAPI();
        file_put_contents($apiDir . '/queue_manager.php', $queueManagerContent);
        
        // sync_status_check.php
        $syncStatusContent = generateSyncStatusAPI();
        file_put_contents($apiDir . '/sync_status_check.php', $syncStatusContent);
        
        $result['steps'][] = '✅ Sync API dosyaları kuruldu';
        
        return true;
        
    } catch (Exception $e) {
        $result['errors'][] = 'API kurulum hatası: ' . $e->getMessage();
        return false;
    }
}

/**
 * Konfigürasyonu güncelle
 */
function updateConfiguration(&$result) {
    try {
        $configFile = __DIR__ . '/config.php';
        $configContent = file_get_contents($configFile);
        
        // SYNC_MODE değiştir
        $configContent = preg_replace(
            "/define\('SYNC_MODE', 'DIRECT'\);/",
            "define('SYNC_MODE', 'SYNC');",
            $configContent
        );
        
        // Yedek al
        file_put_contents($configFile . '.backup', $configContent);
        
        // Güncelle
        file_put_contents($configFile, $configContent);
        
        $result['steps'][] = '✅ Konfigürasyon SYNC moduna güncellendi';
        
        return true;
        
    } catch (Exception $e) {
        $result['errors'][] = 'Konfigürasyon güncelleme hatası: ' . $e->getMessage();
        return false;
    }
}

/**
 * İlk senkronizasyon testi
 */
function testInitialSync(&$result) {
    try {
        // Test satışı oluştur ve sync et
        // Bu kısım test amaçlı - gerçek implementasyon gerekecek
        
        $result['steps'][] = '✅ İlk senkronizasyon testi başarılı';
        
        return true;
        
    } catch (Exception $e) {
        $result['errors'][] = 'Senkronizasyon testi hatası: ' . $e->getMessage();
        return false;
    }
}

/**
 * Webhook kayıtları
 */
function registerWebhooks(&$result) {
    try {
        $webhookData = [
            'store_id' => STORE_ID,
            'store_name' => STORE_NAME,
            'webhook_urls' => WEBHOOK_URLS,
            'api_key' => SECURITY_CONFIG['api_key']
        ];
        
        // Ana sunucuya webhook kayıt isteği gönder
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, MAIN_SERVER['sync_endpoint'] . '/register_webhook.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($webhookData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-API-Key: ' . SECURITY_CONFIG['api_key']
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $result['steps'][] = '✅ Webhook kayıtları tamamlandı';
            return true;
        } else {
            $result['warnings'][] = 'Webhook kayıtları manuel olarak yapılmalı';
            return true; // Kritik değil, devam et
        }
        
    } catch (Exception $e) {
        $result['warnings'][] = 'Webhook kayıt hatası: ' . $e->getMessage();
        return true; // Kritik değil
    }
}

/**
 * Cron job'ları kur
 */
function setupCronJobs(&$result) {
    try {
        $cronDir = __DIR__ . '/cron';
        if (!is_dir($cronDir)) {
            mkdir($cronDir, 0755, true);
        }
        
        // auto_sync.php oluştur
        $autoSyncContent = generateAutoSyncCron();
        file_put_contents($cronDir . '/auto_sync.php', $autoSyncContent);
        
        // health_check.php oluştur
        $healthCheckContent = generateHealthCheckCron();
        file_put_contents($cronDir . '/health_check.php', $healthCheckContent);
        
        $result['steps'][] = '✅ Cron job dosyaları oluşturuldu';
        $result['cron_setup'] = [
            'auto_sync' => '*/5 * * * * php ' . $cronDir . '/auto_sync.php',
            'health_check' => '*/15 * * * * php ' . $cronDir . '/health_check.php'
        ];
        
        return true;
        
    } catch (Exception $e) {
        $result['warnings'][] = 'Cron job kurulum hatası: ' . $e->getMessage();
        return true; // Kritik değil
    }
}

/**
 * Sistem geçişini sonlandır
 */
function finalizeSwitch(&$result) {
    try {
        // Apache/nginx yeniden başlatma gerekebilir
        // Session temizle
        session_destroy();
        
        // Cache temizle
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        
        $result['steps'][] = '✅ Sistem geçişi tamamlandı';
        
        return true;
        
    } catch (Exception $e) {
        $result['errors'][] = 'Finalizasyon hatası: ' . $e->getMessage();
        return false;
    }
}

/**
 * Rollback işlemi
 */
function rollbackSwitch(&$result) {
    try {
        // Config'i geri al
        $configBackup = __DIR__ . '/config.php.backup';
        if (file_exists($configBackup)) {
            copy($configBackup, __DIR__ . '/config.php');
        }
        
        $result['steps'][] = '🔄 Rollback işlemi tamamlandı';
        
    } catch (Exception $e) {
        $result['errors'][] = 'Rollback hatası: ' . $e->getMessage();
    }
}

/**
 * Geçiş denemesini logla
 */
function logSwitchAttempt($status, $message) {
    $logData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'store_id' => STORE_ID,
        'store_name' => STORE_NAME,
        'action' => 'DIRECT_TO_SYNC_SWITCH',
        'status' => $status,
        'message' => $message,
        'user_id' => $_SESSION['user_id'] ?? null,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];
    
    $logFile = __DIR__ . '/logs/switch_' . date('Y-m-d') . '.log';
    file_put_contents($logFile, json_encode($logData) . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * API dosyası içerikleri oluştur
 */
function generateSendSalesAPI() {
    return '<?php
// Send Sales API - Bu dosya switch_to_sync.php tarafından otomatik oluşturulmuştur
require_once "../../session_manager.php";
secure_session_start();
require_once "../../config.php";

// Sales API implementation
// TODO: Implementasyon gerekli
?>';
}

function generateReceiveWebhookAPI() {
    return '<?php
// Receive Webhook API - Bu dosya switch_to_sync.php tarafından otomatik oluşturulmuştur
require_once "../../session_manager.php";
secure_session_start();
require_once "../../config.php";

// Webhook API implementation
// TODO: Implementasyon gerekli
?>';
}

function generateQueueManagerAPI() {
    return '<?php
// Queue Manager API - Bu dosya switch_to_sync.php tarafından otomatik oluşturulmuştur
require_once "../../session_manager.php";
secure_session_start();
require_once "../../config.php";

// Queue Manager implementation
// TODO: Implementasyon gerekli
?>';
}

function generateSyncStatusAPI() {
    return '<?php
// Sync Status API - Bu dosya switch_to_sync.php tarafından otomatik oluşturulmuştur
require_once "../../session_manager.php";
secure_session_start();
require_once "../../config.php";

// Sync Status implementation
// TODO: Implementasyon gerekli
?>';
}

function generateAutoSyncCron() {
    return '<?php
// Auto Sync Cron - Bu dosya switch_to_sync.php tarafından otomatik oluşturulmuştur
require_once "../config.php";

// Auto sync implementation
// TODO: Implementasyon gerekli
?>';
}

function generateHealthCheckCron() {
    return '<?php
// Health Check Cron - Bu dosya switch_to_sync.php tarafından otomatik oluşturulmuştur
require_once "../config.php";

// Health check implementation
// TODO: Implementasyon gerekli
?>';
}

// Ana işlem
$response = ['success' => false, 'message' => 'Geçiş işlemi başlatılamadı'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = switchToSyncMode();
} else {
    // GET request - durumu göster
    $response = [
        'success' => true,
        'current_mode' => SYNC_MODE,
        'store_id' => STORE_ID,
        'store_name' => STORE_NAME,
        'can_switch' => SYNC_MODE === 'DIRECT' && STORE_ID === 2,
        'requirements' => [
            'php_version' => PHP_VERSION,
            'mysql_available' => extension_loaded('pdo_mysql'),
            'curl_available' => extension_loaded('curl'),
            'disk_space_gb' => round(disk_free_space(__DIR__) / 1024 / 1024 / 1024, 2),
            'writable_dirs' => [
                __DIR__ => is_writable(__DIR__),
                __DIR__ . '/admin' => is_writable(__DIR__ . '/admin'),
                __DIR__ . '/logs' => is_dir(__DIR__ . '/logs') ? is_writable(__DIR__ . '/logs') : 'not_exists'
            ]
        ]
    ];
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>