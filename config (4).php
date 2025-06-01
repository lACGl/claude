<?php
/**
 * POS Sistemi Konfigürasyon Dosyası
 * v2.1 - Hibrit Mimari
 * 
 * Bu dosya tüm sistem ayarlarını içerir
 * Dolunay: DIRECT → SYNC geçişi için kullanılır
 */

// Kritik Sistem Ayarları
define('STORE_ID', 2); // 1=Merkez, 2=Dolunay
define('STORE_NAME', 'Dolunay Mağaza');

// 🚀 Çalışma Modu - ÖNEMLİ!
// DIRECT: Ana sunucuya direkt bağlantı (Dolunay şu an)
// SYNC: Local DB + Senkronizasyon (Merkez + Dolunay gelecek)
define('SYNC_MODE', 'DIRECT'); // DIRECT veya SYNC

// Sunucu Bilgileri
define('MAIN_SERVER', [
    'host' => 'pos.incikirtasiye.com',
    'api_url' => 'https://pos.incikirtasiye.com/admin/api',
    'sync_endpoint' => 'https://pos.incikirtasiye.com/admin/api/sync',
    'webhook_secret' => 'pos_sync_2025_secure_key_v21'
]);

// Local Server Bilgileri (SYNC modda kullanılır)
define('LOCAL_SERVER', [
    'host' => 'localhost',
    'port' => 80,
    'ssl' => false, // Sabit IP alındıktan sonra true
    'api_url' => 'http://localhost/admin/api/local_sync',
    'public_url' => STORE_ID == 1 ? 'https://merkez.incikirtasiye.com' : 'https://dolunay.incikirtasiye.com'
]);

// Veritabanı Konfigürasyonu
if (SYNC_MODE === 'DIRECT') {
    // Direkt Ana Sunucu DB
    define('DB_CONFIG', [
        'host' => 'localhost',
        'dbname' => 'incikir2_pos',
        'username' => 'incikir2_posadmin',
        'password' => 'vD3YjbzpPYsc',
        'charset' => 'utf8'
    ]);
} else {
    // Local DB (Sync mode)
    define('DB_CONFIG', [
        'host' => 'localhost',
        'dbname' => 'pos_local_' . STORE_ID,
        'username' => 'pos_local',
        'password' => 'local_2025_secure',
        'charset' => 'utf8'
    ]);
}

// Senkronizasyon Ayarları
define('SYNC_CONFIG', [
    'enabled' => SYNC_MODE === 'SYNC',
    'auto_sync_interval' => 300, // 5 dakika
    'webhook_timeout' => 30,
    'retry_attempts' => 3,
    'offline_mode_timeout' => 3600, // 1 saat offline mod
    'batch_size' => 50 // Toplu işlem boyutu
]);

// Real-time Sync Frekansları (saniye)
define('SYNC_FREQUENCIES', [
    'sales' => 0,           // Anında
    'stock' => 0,           // Anında
    'customers' => 0,       // Anında
    'points' => 0,          // Anında
    'credits' => 0,         // Anında (YENİ)
    'prices' => 300,        // 5 dakika
    'products' => 300,      // 5 dakika
    'settings' => 900,      // 15 dakika
    'categories' => 86400,  // Günlük
    'suppliers' => 86400    // Günlük
]);

// Güvenlik Ayarları
define('SECURITY_CONFIG', [
    'api_key' => 'pos_api_2025_' . STORE_ID . '_secure',
    'hmac_secret' => 'pos_hmac_secret_2025_v21',
    'rate_limit' => 100, // dakikada max istek
    'session_timeout' => 28800, // 8 saat
    'max_login_attempts' => 5
]);

// Offline Mode Ayarları
define('OFFLINE_CONFIG', [
    'enabled' => true,
    'max_offline_sales' => 1000,
    'local_storage_days' => 30,
    'auto_retry_interval' => 60 // 1 dakika
]);

// Log Ayarları
define('LOG_CONFIG', [
    'enabled' => true,
    'level' => 'INFO', // DEBUG, INFO, WARNING, ERROR
    'max_file_size' => 10485760, // 10MB
    'retention_days' => 30,
    'sync_log_enabled' => true
]);

// POS Özel Ayarları
define('POS_CONFIG', [
    'receipt_footer' => STORE_NAME . ' - Teşekkür Ederiz',
    'auto_print' => false,
    'barcode_scanner' => true,
    'customer_display' => false,
    'cash_drawer' => false,
    'backup_interval' => 3600 // 1 saat
]);

// Network Ayarları
define('NETWORK_CONFIG', [
    'connection_timeout' => 30,
    'read_timeout' => 60,
    'max_redirects' => 3,
    'user_agent' => 'POS-System-v2.1-Store-' . STORE_ID,
    'ssl_verify' => true
]);

// Hata Yönetimi
define('ERROR_CONFIG', [
    'display_errors' => false,
    'log_errors' => true,
    'error_reporting' => E_ALL & ~E_NOTICE,
    'max_execution_time' => 300 // 5 dakika
]);

// Cache Ayarları
define('CACHE_CONFIG', [
    'enabled' => true,
    'driver' => 'file', // file, redis, memcached
    'ttl' => 3600, // 1 saat
    'prefix' => 'pos_store_' . STORE_ID . '_'
]);

// Webhook URL'leri (SYNC modda kullanılır)
define('WEBHOOK_URLS', [
    'sales_update' => LOCAL_SERVER['api_url'] . '/receive_webhook.php?type=sales',
    'stock_update' => LOCAL_SERVER['api_url'] . '/receive_webhook.php?type=stock',
    'customer_update' => LOCAL_SERVER['api_url'] . '/receive_webhook.php?type=customer',
    'price_update' => LOCAL_SERVER['api_url'] . '/receive_webhook.php?type=price'
]);

// Sistem Durumu Kontrolleri
define('HEALTH_CHECK', [
    'enabled' => true,
    'interval' => 300, // 5 dakika
    'endpoints' => [
        'database' => true,
        'main_server' => SYNC_MODE === 'SYNC',
        'local_api' => SYNC_MODE === 'SYNC',
        'disk_space' => true,
        'memory_usage' => true
    ]
]);

// Bildirim Ayarları
define('NOTIFICATION_CONFIG', [
    'sync_errors' => true,
    'connection_lost' => true,
    'low_stock' => true,
    'daily_report' => true,
    'email_enabled' => false,
    'sms_enabled' => false
]);

// Feature Flags - Yeni özellikler için
define('FEATURE_FLAGS', [
    'customer_credit_system' => true,  // YENİ: Müşteri borç sistemi
    'multi_store_reports' => true,     // YENİ: Çok mağaza raporları
    'advanced_pricing' => true,        // YENİ: Gelişmiş fiyatlandırma
    'loyalty_program' => true,         // Mevcut: Puan sistemi
    'barcode_generation' => true,      // Mevcut: Barkod üretimi
    'inventory_alerts' => true,        // Stok uyarıları
    'sales_analytics' => true          // Satış analitiği
]);

// Sistem Bilgileri
define('SYSTEM_INFO', [
    'version' => '2.1.0',
    'build_date' => '2025-05-31',
    'php_min_version' => '8.1.0',
    'mysql_min_version' => '5.7.0',
    'timezone' => 'Europe/Istanbul',
    'locale' => 'tr_TR.UTF-8'
]);

// Timezone ayarla
date_default_timezone_set(SYSTEM_INFO['timezone']);

// Locale ayarla
setlocale(LC_ALL, SYSTEM_INFO['locale']);

// Hata raporlama ayarla
error_reporting(ERROR_CONFIG['error_reporting']);
ini_set('display_errors', ERROR_CONFIG['display_errors']);
ini_set('log_errors', ERROR_CONFIG['log_errors']);
ini_set('max_execution_time', ERROR_CONFIG['max_execution_time']);

/**
 * Sistem modunu kontrol et
 * @return string DIRECT veya SYNC
 */
function getCurrentMode() {
    return SYNC_MODE;
}

/**
 * Mağaza bilgilerini al
 * @return array Mağaza detayları
 */
function getStoreInfo() {
    return [
        'id' => STORE_ID,
        'name' => STORE_NAME,
        'mode' => SYNC_MODE,
        'version' => SYSTEM_INFO['version']
    ];
}

/**
 * Ana sunucu bağlantısını kontrol et
 * @return bool Bağlantı durumu
 */
function checkMainServerConnection() {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, MAIN_SERVER['api_url'] . '/health_check.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, NETWORK_CONFIG['ssl_verify']);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode === 200;
}

/**
 * Konfigürasyon logla
 */
function logSystemConfig() {
    if (LOG_CONFIG['enabled']) {
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'store_id' => STORE_ID,
            'store_name' => STORE_NAME,
            'mode' => SYNC_MODE,
            'version' => SYSTEM_INFO['version'],
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time')
        ];
        
        $logFile = __DIR__ . '/logs/config_' . date('Y-m-d') . '.log';
        
        // Log dizinini oluştur
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        file_put_contents($logFile, json_encode($logData) . "\n", FILE_APPEND | LOCK_EX);
    }
}

// Sistem başlatıldığında config logla
if (LOG_CONFIG['enabled']) {
    logSystemConfig();
}

// Global hata yakalayıcı
set_error_handler(function($severity, $message, $file, $line) {
    if (LOG_CONFIG['enabled'] && $severity & error_reporting()) {
        $errorLog = [
            'timestamp' => date('Y-m-d H:i:s'),
            'severity' => $severity,
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'store_id' => STORE_ID
        ];
        
        $logFile = __DIR__ . '/logs/errors_' . date('Y-m-d') . '.log';
        file_put_contents($logFile, json_encode($errorLog) . "\n", FILE_APPEND | LOCK_EX);
    }
});

// Session ayarları (POS için optimize edilmiş)
ini_set('session.gc_maxlifetime', SECURITY_CONFIG['session_timeout']);
ini_set('session.cookie_lifetime', SECURITY_CONFIG['session_timeout']);
ini_set('session.cookie_secure', NETWORK_CONFIG['ssl_verify']);
ini_set('session.cookie_httponly', true);
ini_set('session.use_strict_mode', true);

// Memory limit artır (POS için)
ini_set('memory_limit', '256M');

/**
 * Debug bilgilerini görüntüle (sadece development)
 */
if (defined('DEBUG_MODE') && DEBUG_MODE === true) {
    echo "<!-- POS System v" . SYSTEM_INFO['version'] . " -->\n";
    echo "<!-- Store: " . STORE_NAME . " (ID: " . STORE_ID . ") -->\n";
    echo "<!-- Mode: " . SYNC_MODE . " -->\n";
    echo "<!-- Config loaded at: " . date('Y-m-d H:i:s') . " -->\n";
}
?>