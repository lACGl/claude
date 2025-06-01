<?php
/**
 * POS Sistemi - Direkt Ana Sunucu Veritabanı Bağlantısı
 * v2.1 - Hibrit Mimari
 * 
 * Bu dosya DIRECT modda ana sunucuya direkt bağlantı sağlar
 * Dolunay mağaza için kullanılır (STORE_ID=2, MODE=DIRECT)
 */

require_once __DIR__ . '/config.php';

// Güvenlik kontrolü - sadece DIRECT modda çalışır
if (SYNC_MODE !== 'DIRECT') {
    die('Bu bağlantı dosyası sadece DIRECT modda kullanılabilir!');
}

// Session manager dahil et
require_once __DIR__ . '/session_manager.php';

// Ana sunucu veritabanı bilgileri
$host = DB_CONFIG['host'];
$dbname = DB_CONFIG['dbname'];
$username = DB_CONFIG['username'];
$password = DB_CONFIG['password'];
$charset = DB_CONFIG['charset'];

try {
    // PDO bağlantısı oluştur
    $dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";
    
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false, // Güvenlik için persistent kapalı
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset}",
        PDO::ATTR_TIMEOUT => NETWORK_CONFIG['connection_timeout']
    ];
    
    $conn = new PDO($dsn, $username, $password, $options);
    
    // Bağlantı başarılı logla
    if (LOG_CONFIG['enabled']) {
        logDatabaseConnection('SUCCESS', 'Ana sunucuya başarıyla bağlandı');
    }
    
    // Store context ayarla (hangi mağazadan geldiğini belirt)
    $conn->exec("SET @store_id = " . STORE_ID);
    $conn->exec("SET @store_name = '" . STORE_NAME . "'");
    $conn->exec("SET @connection_mode = 'DIRECT'");
    
} catch (PDOException $e) {
    $errorMessage = 'Ana sunucu veritabanı bağlantı hatası: ' . $e->getMessage();
    
    // Hata logla
    if (LOG_CONFIG['enabled']) {
        logDatabaseConnection('ERROR', $errorMessage);
    }
    
    // Geliştirici modunda detaylı hata göster
    if (defined('DEBUG_MODE') && DEBUG_MODE === true) {
        die($errorMessage);
    }
    
    // Prodüksiyonda genel hata mesajı
    die('Veritabanı bağlantısı kurulamadı. Lütfen sistem yöneticisiyle iletişime geçin.');
}

/**
 * Veritabanı bağlantı durumunu logla
 * @param string $status Durum: SUCCESS, ERROR, WARNING
 * @param string $message Mesaj
 */
function logDatabaseConnection($status, $message) {
    if (!LOG_CONFIG['enabled']) return;
    
    $logData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'store_id' => STORE_ID,
        'store_name' => STORE_NAME,
        'mode' => 'DIRECT',
        'status' => $status,
        'message' => $message,
        'host' => DB_CONFIG['host'],
        'database' => DB_CONFIG['dbname'],
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'CLI',
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'localhost',
        'memory_usage' => memory_get_usage(true),
        'memory_peak' => memory_get_peak_usage(true)
    ];
    
    $logFile = __DIR__ . '/logs/database_' . date('Y-m-d') . '.log';
    
    // Log dizinini oluştur
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($logFile, json_encode($logData) . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Ana sunucu bağlantısını test et
 * @return array Test sonucu
 */
function testMainServerConnection() {
    global $conn;
    
    try {
        // Basit sorgu ile test
        $stmt = $conn->query("SELECT 1 as test_connection, NOW() as server_time");
        $result = $stmt->fetch();
        
        if ($result && $result['test_connection'] == 1) {
            return [
                'success' => true,
                'server_time' => $result['server_time'],
                'latency' => measureLatency(),
                'status' => 'Ana sunucu bağlantısı aktif'
            ];
        }
        
        return [
            'success' => false,
            'status' => 'Ana sunucu sorgu testi başarısız'
        ];
        
    } catch (PDOException $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'status' => 'Ana sunucu bağlantı testi başarısız'
        ];
    }
}

/**
 * Veritabanı latency ölç
 * @return float Latency (ms)
 */
function measureLatency() {
    global $conn;
    
    $start = microtime(true);
    
    try {
        $conn->query("SELECT 1");
        $end = microtime(true);
        return round(($end - $start) * 1000, 2);
    } catch (Exception $e) {
        return -1;
    }
}

/**
 * Mağaza özel ayarları yükle
 * @return array Mağaza ayarları
 */
function loadStoreSettings() {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT config_key, config_value, data_type 
            FROM store_config 
            WHERE magaza_id = ? 
            AND is_synced = 1
        ");
        $stmt->execute([STORE_ID]);
        
        $settings = [];
        while ($row = $stmt->fetch()) {
            $value = $row['config_value'];
            
            // Veri tipine göre değeri dönüştür
            switch ($row['data_type']) {
                case 'integer':
                    $value = (int)$value;
                    break;
                case 'float':
                    $value = (float)$value;
                    break;
                case 'boolean':
                    $value = (bool)$value;
                    break;
                case 'json':
                    $value = json_decode($value, true);
                    break;
                default:
                    // string olarak bırak
                    break;
            }
            
            $settings[$row['config_key']] = $value;
        }
        
        return $settings;
        
    } catch (PDOException $e) {
        logDatabaseConnection('WARNING', 'Mağaza ayarları yüklenemedi: ' . $e->getMessage());
        return [];
    }
}

/**
 * Sistem sağlık kontrolü
 * @return array Sağlık durumu
 */
function systemHealthCheck() {
    global $conn;
    
    $health = [
        'timestamp' => date('Y-m-d H:i:s'),
        'store_id' => STORE_ID,
        'mode' => 'DIRECT',
        'database' => false,
        'main_server' => false,
        'memory_usage' => 0,
        'disk_space' => 0,
        'errors' => []
    ];
    
    try {
        // Veritabanı testi
        $dbTest = testMainServerConnection();
        $health['database'] = $dbTest['success'];
        $health['latency'] = $dbTest['latency'] ?? 0;
        
        if (!$dbTest['success']) {
            $health['errors'][] = 'Veritabanı bağlantı hatası';
        }
        
        // Ana sunucu API testi
        $health['main_server'] = checkMainServerConnection();
        if (!$health['main_server']) {
            $health['errors'][] = 'Ana sunucu API\'sine ulaşılamıyor';
        }
        
        // Memory kullanımı
        $health['memory_usage'] = round(memory_get_usage(true) / 1024 / 1024, 2); // MB
        
        // Disk alanı
        $health['disk_space'] = round(disk_free_space(__DIR__) / 1024 / 1024 / 1024, 2); // GB
        
        // Genel durum
        $health['overall_status'] = empty($health['errors']) ? 'HEALTHY' : 'UNHEALTHY';
        
    } catch (Exception $e) {
        $health['errors'][] = 'Sistem kontrolü hatası: ' . $e->getMessage();
        $health['overall_status'] = 'ERROR';
    }
    
    // Sağlık durumunu logla
    if (LOG_CONFIG['enabled']) {
        logDatabaseConnection('INFO', 'Sistem sağlık kontrolü: ' . $health['overall_status']);
    }
    
    return $health;
}

/**
 * Bağlantı sorunları için fallback mekanizması
 */
function handleConnectionFailure() {
    // Offline mode aktif et
    if (OFFLINE_CONFIG['enabled']) {
        // Offline sales için localStorage/session kullan
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $_SESSION['offline_mode'] = true;
        $_SESSION['offline_since'] = time();
        
        logDatabaseConnection('WARNING', 'Offline moda geçildi');
        
        return true;
    }
    
    return false;
}

/**
 * Otomatik yeniden bağlanma
 */
function autoReconnect() {
    global $conn;
    
    try {
        // Bağlantıyı test et
        $conn->query("SELECT 1");
        return true;
        
    } catch (PDOException $e) {
        logDatabaseConnection('WARNING', 'Bağlantı koptu, yeniden bağlanılıyor...');
        
        // Yeniden bağlan
        try {
            $dsn = "mysql:host=" . DB_CONFIG['host'] . ";dbname=" . DB_CONFIG['dbname'] . ";charset=" . DB_CONFIG['charset'];
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => NETWORK_CONFIG['connection_timeout']
            ];
            
            $conn = new PDO($dsn, DB_CONFIG['username'], DB_CONFIG['password'], $options);
            
            // Store context yeniden ayarla
            $conn->exec("SET @store_id = " . STORE_ID);
            $conn->exec("SET @store_name = '" . STORE_NAME . "'");
            $conn->exec("SET @connection_mode = 'DIRECT'");
            
            logDatabaseConnection('SUCCESS', 'Yeniden bağlantı başarılı');
            return true;
            
        } catch (PDOException $e2) {
            logDatabaseConnection('ERROR', 'Yeniden bağlantı başarısız: ' . $e2->getMessage());
            
            // Offline mode aktif et
            return handleConnectionFailure();
        }
    }
}

// Mağaza ayarlarını yükle ve global değişkenlere ata
$storeSettings = loadStoreSettings();
if (!empty($storeSettings)) {
    // Global store settings tanımla
    define('STORE_SETTINGS', $storeSettings);
}

// Bağlantı monitoring için heartbeat
if (HEALTH_CHECK['enabled']) {
    register_shutdown_function(function() {
        // Sayfa sonunda bağlantı durumunu kontrol et
        if (rand(1, 100) <= 5) { // %5 ihtimalle health check
            $health = systemHealthCheck();
            
            // Kritik sorun varsa bildir
            if ($health['overall_status'] === 'UNHEALTHY' || $health['overall_status'] === 'ERROR') {
                if (NOTIFICATION_CONFIG['sync_errors']) {
                    // Burada email/SMS bildirimi gönderebilirsiniz
                    logDatabaseConnection('CRITICAL', 'Sistem sağlığında sorun tespit edildi');
                }
            }
        }
    });
}

// Debug modunda bağlantı bilgilerini göster
if (defined('DEBUG_MODE') && DEBUG_MODE === true) {
    echo "\n<!-- Database Connection Info -->";
    echo "\n<!-- Host: " . DB_CONFIG['host'] . " -->";
    echo "\n<!-- Database: " . DB_CONFIG['dbname'] . " -->";
    echo "\n<!-- Store ID: " . STORE_ID . " -->";
    echo "\n<!-- Mode: DIRECT -->";
    echo "\n<!-- Connected at: " . date('Y-m-d H:i:s') . " -->";
}
?>