<?php
/**
 * Otomatik Senkronizasyon Cron Job
 * ================================
 * Her 5 dakikada çalışır: */5 * * * * /usr/bin/php /path/to/cron/auto_sync.php
 * 
 * İşlevler:
 * - Bekleyen sync işlemlerini kontrol eder
 * - Başarısız sync'leri yeniden dener
 * - Health check yapar
 * - Log tutar
 */

// Güvenlik ve session
require_once dirname(__DIR__) . '/session_manager.php';
require_once dirname(__DIR__) . '/db_connection.php';

// CLI'den çalıştığını kontrol et
if (php_sapi_name() !== 'cli') {
    die("Bu script sadece command line'dan çalıştırılabilir\n");
}

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300); // 5 dakika timeout

class AutoSyncManager {
    private $conn;
    private $storeId;
    private $syncMode;
    private $logFile;
    private $config;
    
    public function __construct($conn) {
        $this->conn = $conn;
        $this->loadConfig();
        $this->logFile = dirname(__DIR__) . '/logs/auto_sync_' . date('Y-m-d') . '.log';
        $this->ensureLogDirectory();
        
        $this->log("🚀 AutoSync başlatıldı - Mağaza: {$this->storeId}, Mod: {$this->syncMode}");
    }
    
    /**
     * Konfigürasyonu yükle
     */
    private function loadConfig() {
        // Config dosyasından ayarları al
        $configFile = dirname(__DIR__) . '/config.php';
        if (file_exists($configFile)) {
            include $configFile;
            $this->storeId = defined('STORE_ID') ? STORE_ID : 1;
            $this->syncMode = defined('SYNC_MODE') ? SYNC_MODE : 'SYNC';
        } else {
            $this->storeId = 1;
            $this->syncMode = 'SYNC';
        }
        
        // Default config
        $this->config = [
            'max_retry_attempts' => 3,
            'retry_delay_minutes' => 15,
            'batch_size' => 50,
            'timeout_seconds' => 30,
            'cleanup_days' => 7,
            'webhook_timeout' => 10
        ];
        
        // Veritabanından ayarları yükle
        $this->loadDatabaseConfig();
    }
    
    /**
     * Veritabanından konfigürasyon yükle
     */
    private function loadDatabaseConfig() {
        try {
            $stmt = $this->conn->prepare("
                SELECT config_key, config_value 
                FROM store_config 
                WHERE magaza_id = ? AND config_key LIKE 'sync_%'
            ");
            $stmt->execute([$this->storeId]);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $key = str_replace('sync_', '', $row['config_key']);
                $this->config[$key] = $row['config_value'];
            }
        } catch (Exception $e) {
            $this->log("⚠️ Config yükleme hatası: " . $e->getMessage());
        }
    }
    
    /**
     * Ana sync işlemini çalıştır
     */
    public function run() {
        try {
            // Sadece SYNC modunda çalış
            if ($this->syncMode !== 'SYNC') {
                $this->log("ℹ️ Direct mode tespit edildi, sync atlanıyor");
                return;
            }
            
            $startTime = microtime(true);
            $this->log("🔄 Otomatik sync başlıyor...");
            
            // Ana işlemler
            $results = [
                'pending_processed' => $this->processPendingSync(),
                'failed_retried' => $this->retryFailedSync(),
                'webhooks_sent' => $this->sendPendingWebhooks(),
                'cleanup_done' => $this->cleanupOldRecords()
            ];
            
            // Health check
            $healthStatus = $this->performHealthCheck();
            
            $duration = round((microtime(true) - $startTime) * 1000);
            
            $this->log("✅ Sync tamamlandı ({$duration}ms)");
            $this->log("📊 İstatistikler: " . json_encode($results));
            
            // İstatistikleri kaydet
            $this->saveStats($results, $duration);
            
        } catch (Exception $e) {
            $this->log("❌ Sync hatası: " . $e->getMessage());
            $this->handleError($e);
        }
    }
    
    /**
     * Bekleyen sync işlemlerini proces et
     */
    private function processPendingSync() {
        try {
            $stmt = $this->conn->prepare("
                SELECT * FROM sync_queue 
                WHERE magaza_id = ? 
                AND status = 'pending' 
                AND scheduled_at <= NOW()
                AND attempts < max_attempts
                ORDER BY priority DESC, created_at ASC
                LIMIT ?
            ");
            $stmt->execute([$this->storeId, $this->config['batch_size']]);
            $pendingItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($pendingItems)) {
                return 0;
            }
            
            $this->log("📋 {count($pendingItems)} adet bekleyen sync işlemi bulundu");
            
            $processed = 0;
            foreach ($pendingItems as $item) {
                if ($this->processSyncItem($item)) {
                    $processed++;
                }
                
                // CPU'ya nefes ver
                usleep(100000); // 0.1 saniye
            }
            
            return $processed;
            
        } catch (Exception $e) {
            $this->log("❌ Pending sync hatası: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Tek sync öğesini işle
     */
    private function processSyncItem($item) {
        try {
            $this->log("🔄 İşleniyor: {$item['operation_type']} (ID: {$item['id']})");
            
            // İşlemi güncelle
            $this->updateSyncStatus($item['id'], 'processing');
            
            // Sync API'sine gönder
            $result = $this->sendToSyncAPI($item);
            
            if ($result['success']) {
                // Başarılı
                $this->updateSyncStatus($item['id'], 'completed', null, $result['message'] ?? 'Başarılı');
                $this->log("✅ Başarılı: {$item['operation_type']} (ID: {$item['id']})");
                return true;
            } else {
                // Başarısız - retry için işaretle
                $this->handleSyncFailure($item, $result['message'] ?? 'Bilinmeyen hata');
                return false;
            }
            
        } catch (Exception $e) {
            $this->handleSyncFailure($item, $e->getMessage());
            return false;
        }
    }
    
    /**
     * Sync API'sine gönder
     */
    private function sendToSyncAPI($item) {
        $endpoints = [
            'sale' => '/admin/api/sync/receive_sales.php',
            'stock_update' => '/admin/api/sync/receive_stock.php',
            'customer_update' => '/admin/api/sync/receive_customer.php',
            'price_update' => '/admin/api/sync/receive_prices.php'
        ];
        
        $endpoint = $endpoints[$item['operation_type']] ?? $endpoints['sale'];
        $url = 'https://pos.incikirtasiye.com' . $endpoint;
        
        // POST verisini hazırla
        $postData = [
            'store_id' => $this->storeId,
            'operation_type' => $item['operation_type'],
            'data' => json_decode($item['data_json'], true),
            'sync_hash' => $item['sync_hash'],
            'timestamp' => $item['created_at']
        ];
        
        // cURL ile gönder
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->config['timeout_seconds'],
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Store-ID: ' . $this->storeId,
                'X-Sync-Key: ' . $this->generateSyncKey($item)
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("cURL hatası: $error");
        }
        
        if ($httpCode !== 200) {
            throw new Exception("HTTP $httpCode hatası");
        }
        
        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Geçersiz JSON yanıtı");
        }
        
        return $result;
    }
    
    /**
     * Sync başarısızlığını ele al
     */
    private function handleSyncFailure($item, $errorMessage) {
        $attempts = $item['attempts'] + 1;
        
        if ($attempts >= $this->config['max_retry_attempts']) {
            // Maksimum deneme sayısına ulaşıldı
            $this->updateSyncStatus($item['id'], 'failed', null, $errorMessage);
            $this->log("💀 Maksimum deneme aşıldı: {$item['operation_type']} (ID: {$item['id']}) - $errorMessage");
            
            // Kritik işlemler için backup
            if (in_array($item['operation_type'], ['sale', 'debt_payment'])) {
                $this->saveToBackup($item, $errorMessage);
            }
        } else {
            // Yeniden deneme için programla
            $nextAttempt = date('Y-m-d H:i:s', strtotime("+{$this->config['retry_delay_minutes']} minutes"));
            $this->updateSyncStatus($item['id'], 'pending', $nextAttempt, $errorMessage, $attempts);
            $this->log("🔄 Yeniden deneme programlandı: {$item['operation_type']} (ID: {$item['id']}) - $nextAttempt");
        }
    }
    
    /**
     * Başarısız sync'leri yeniden dene
     */
    private function retryFailedSync() {
        try {
            // Retry zamanı gelen başarısız işlemleri al
            $stmt = $this->conn->prepare("
                SELECT * FROM sync_queue 
                WHERE magaza_id = ? 
                AND status = 'failed'
                AND attempts < max_attempts
                AND scheduled_at <= NOW()
                ORDER BY created_at ASC
                LIMIT 10
            ");
            $stmt->execute([$this->storeId]);
            $failedItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($failedItems)) {
                return 0;
            }
            
            $this->log("🔄 {count($failedItems)} adet başarısız sync yeniden deneniyor");
            
            $retried = 0;
            foreach ($failedItems as $item) {
                // Status'u pending'e çevir ve tekrar dene
                $this->updateSyncStatus($item['id'], 'pending');
                if ($this->processSyncItem($item)) {
                    $retried++;
                }
            }
            
            return $retried;
            
        } catch (Exception $e) {
            $this->log("❌ Retry sync hatası: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Bekleyen webhook'ları gönder
     */
    private function sendPendingWebhooks() {
        try {
            // Gönderilecek webhook'ları al
            $stmt = $this->conn->prepare("
                SELECT DISTINCT target_store_id 
                FROM sync_queue 
                WHERE magaza_id = ? 
                AND status = 'completed'
                AND processed_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $stmt->execute([$this->storeId]);
            $targetStores = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $webhooksSent = 0;
            foreach ($targetStores as $targetStoreId) {
                if ($this->sendWebhookToStore($targetStoreId)) {
                    $webhooksSent++;
                }
            }
            
            return $webhooksSent;
            
        } catch (Exception $e) {
            $this->log("❌ Webhook gönderme hatası: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Belirli mağazaya webhook gönder
     */
    private function sendWebhookToStore($targetStoreId) {
        try {
            // Hedef mağaza bilgilerini al
            $stmt = $this->conn->prepare("
                SELECT config_value as webhook_url 
                FROM store_config 
                WHERE magaza_id = ? AND config_key = 'webhook_url'
            ");
            $stmt->execute([$targetStoreId]);
            $webhookUrl = $stmt->fetchColumn();
            
            if (!$webhookUrl) {
                return false; // Webhook URL tanımlı değil
            }
            
            // Son güncellemeleri al
            $stmt = $this->conn->prepare("
                SELECT operation_type, COUNT(*) as count
                FROM sync_queue 
                WHERE magaza_id = ? 
                AND status = 'completed'
                AND processed_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                GROUP BY operation_type
            ");
            $stmt->execute([$this->storeId]);
            $updates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($updates)) {
                return false;
            }
            
            // Webhook payload'ı hazırla
            $payload = [
                'source_store_id' => $this->storeId,
                'target_store_id' => $targetStoreId,
                'timestamp' => date('c'),
                'updates' => $updates,
                'signature' => $this->generateWebhookSignature($updates)
            ];
            
            // Webhook gönder
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $webhookUrl,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->config['webhook_timeout'],
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Webhook-Source: pos-sync-cron'
                ]
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $this->log("📡 Webhook gönderildi: Mağaza $targetStoreId");
                return true;
            } else {
                $this->log("⚠️ Webhook hatası: Mağaza $targetStoreId (HTTP $httpCode)");
                return false;
            }
            
        } catch (Exception $e) {
            $this->log("❌ Webhook gönderme hatası (Mağaza $targetStoreId): " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Eski kayıtları temizle
     */
    private function cleanupOldRecords() {
        try {
            $cleanupDate = date('Y-m-d H:i:s', strtotime("-{$this->config['cleanup_days']} days"));
            
            // Tamamlanmış eski sync kayıtlarını sil
            $stmt = $this->conn->prepare("
                DELETE FROM sync_queue 
                WHERE magaza_id = ? 
                AND status IN ('completed', 'failed')
                AND created_at < ?
            ");
            $stmt->execute([$this->storeId, $cleanupDate]);
            $deleted = $stmt->rowCount();
            
            if ($deleted > 0) {
                $this->log("🧹 $deleted adet eski sync kaydı temizlendi");
            }
            
            return $deleted;
            
        } catch (Exception $e) {
            $this->log("❌ Cleanup hatası: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Health check gerçekleştir
     */
    private function performHealthCheck() {
        try {
            $health = [
                'timestamp' => date('c'),
                'store_id' => $this->storeId,
                'sync_mode' => $this->syncMode,
                'database' => $this->checkDatabase(),
                'api_connectivity' => $this->checkAPIConnectivity(),
                'queue_health' => $this->checkQueueHealth(),
                'disk_space' => $this->checkDiskSpace()
            ];
            
            // Health durumunu kaydet
            $this->saveHealthStatus($health);
            
            // Kritik sorunları logla
            foreach ($health as $check => $status) {
                if (is_array($status) && isset($status['status']) && $status['status'] === 'error') {
                    $this->log("⚠️ Health Check HATA - $check: " . $status['message']);
                }
            }
            
            return $health;
            
        } catch (Exception $e) {
            $this->log("❌ Health check hatası: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Veritabanı sağlığını kontrol et
     */
    private function checkDatabase() {
        try {
            $stmt = $this->conn->query("SELECT 1");
            $result = $stmt->fetch();
            
            return [
                'status' => 'ok',
                'message' => 'Veritabanı bağlantısı aktif'
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Veritabanı bağlantı hatası: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * API bağlantısını kontrol et
     */
    private function checkAPIConnectivity() {
        try {
            $url = 'https://pos.incikirtasiye.com/admin/api/sync/sync_status.php';
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['X-Store-ID: ' . $this->storeId]
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                return [
                    'status' => 'ok',
                    'message' => 'Ana sunucu API erişilebilir'
                ];
            } else {
                return [
                    'status' => 'warning',
                    'message' => "API yanıt kodu: $httpCode"
                ];
            }
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'API bağlantı hatası: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Kuyruk sağlığını kontrol et
     */
    private function checkQueueHealth() {
        try {
            $stmt = $this->conn->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN attempts >= max_attempts THEN 1 ELSE 0 END) as max_attempts
                FROM sync_queue 
                WHERE magaza_id = ?
            ");
            $stmt->execute([$this->storeId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $status = 'ok';
            $issues = [];
            
            if ($stats['pending'] > 100) {
                $status = 'warning';
                $issues[] = "Çok fazla bekleyen işlem ({$stats['pending']})";
            }
            
            if ($stats['failed'] > 50) {
                $status = 'warning';
                $issues[] = "Çok fazla başarısız işlem ({$stats['failed']})";
            }
            
            if ($stats['max_attempts'] > 10) {
                $status = 'error';
                $issues[] = "Maksimum deneme aşan işlemler ({$stats['max_attempts']})";
            }
            
            return [
                'status' => $status,
                'message' => empty($issues) ? 'Kuyruk sağlıklı' : implode(', ', $issues),
                'stats' => $stats
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Kuyruk kontrol hatası: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Disk alanını kontrol et
     */
    private function checkDiskSpace() {
        try {
            $bytes = disk_free_space(dirname(__DIR__));
            $mb = round($bytes / (1024 * 1024));
            
            $status = 'ok';
            if ($mb < 100) { // 100MB'den az
                $status = 'error';
            } elseif ($mb < 500) { // 500MB'den az
                $status = 'warning';
            }
            
            return [
                'status' => $status,
                'message' => "Kullanılabilir alan: {$mb}MB",
                'free_space_mb' => $mb
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Disk alanı kontrol hatası: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Sync durumunu güncelle
     */
    private function updateSyncStatus($id, $status, $scheduledAt = null, $errorMessage = null, $attempts = null) {
        try {
            $sql = "UPDATE sync_queue SET status = ?";
            $params = [$status];
            
            if ($scheduledAt !== null) {
                $sql .= ", scheduled_at = ?";
                $params[] = $scheduledAt;
            }
            
            if ($errorMessage !== null) {
                $sql .= ", error_message = ?";
                $params[] = $errorMessage;
            }
            
            if ($attempts !== null) {
                $sql .= ", attempts = ?";
                $params[] = $attempts;
            }
            
            if ($status === 'completed') {
                $sql .= ", processed_at = NOW()";
            }
            
            $sql .= " WHERE id = ?";
            $params[] = $id;
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            
        } catch (Exception $e) {
            $this->log("❌ Status güncelleme hatası: " . $e->getMessage());
        }
    }
    
    /**
     * İstatistikleri kaydet
     */
    private function saveStats($results, $duration) {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO sync_stats (
                    magaza_id, stat_date, total_operations, successful_operations, 
                    failed_operations, avg_sync_time, last_sync_time
                ) VALUES (?, CURDATE(), ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    total_operations = total_operations + VALUES(total_operations),
                    successful_operations = successful_operations + VALUES(successful_operations),
                    failed_operations = failed_operations + VALUES(failed_operations),
                    avg_sync_time = (avg_sync_time + VALUES(avg_sync_time)) / 2,
                    last_sync_time = NOW()
            ");
            
            $total = array_sum($results);
            $successful = $results['pending_processed'] + $results['failed_retried'];
            $failed = $total - $successful;
            
            $stmt->execute([
                $this->storeId,
                $total,
                $successful,
                $failed,
                $duration
            ]);
            
        } catch (Exception $e) {
            $this->log("❌ İstatistik kaydetme hatası: " . $e->getMessage());
        }
    }
    
    /**
     * Health durumunu kaydet
     */
    private function saveHealthStatus($health) {
        try {
            $stmt = $this->conn->prepare("
                UPDATE sync_metadata 
                SET son_sync_tarihi = NOW(),
                    sync_durumu = ?,
                    last_error = ?
                WHERE magaza_id = ? AND tablo_adi = 'health_check'
            ");
            
            $status = 'basarili';
            $errors = [];
            
            foreach ($health as $check => $result) {
                if (is_array($result) && isset($result['status']) && $result['status'] === 'error') {
                    $status = 'hata';
                    $errors[] = "$check: " . $result['message'];
                }
            }
            
            $errorMessage = empty($errors) ? null : implode('; ', $errors);
            
            $stmt->execute([$status, $errorMessage, $this->storeId]);
            
            // Eğer kayıt yoksa ekle
            if ($stmt->rowCount() === 0) {
                $stmt = $this->conn->prepare("
                    INSERT INTO sync_metadata (magaza_id, tablo_adi, son_sync_tarihi, sync_durumu, last_error)
                    VALUES (?, 'health_check', NOW(), ?, ?)
                ");
                $stmt->execute([$this->storeId, $status, $errorMessage]);
            }
            
        } catch (Exception $e) {
            $this->log("❌ Health durumu kaydetme hatası: " . $e->getMessage());
        }
    }
    
    /**
     * Backup'a kaydet
     */
    private function saveToBackup($item, $errorMessage) {
        try {
            $backupDir = dirname(__DIR__) . '/backup/failed_sync';
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }
            
            $backupFile = $backupDir . '/failed_' . date('Y-m-d') . '.json';
            
            $backupData = [
                'timestamp' => date('c'),
                'store_id' => $this->storeId,
                'item' => $item,
                'error' => $errorMessage
            ];
            
            file_put_contents($backupFile, json_encode($backupData) . "\n", FILE_APPEND | LOCK_EX);
            
            $this->log("💾 Kritik veri backup'a kaydedildi: $backupFile");
            
        } catch (Exception $e) {
            $this->log("❌ Backup kaydetme hatası: " . $e->getMessage());
        }
    }
    
    /**
     * Hata ele alma
     */
    private function handleError($exception) {
        // Kritik hataları email ile bildir (opsiyonel)
        $this->log("🚨 KRİTİK HATA: " . $exception->getMessage());
        $this->log("📍 Stack Trace: " . $exception->getTraceAsString());
    }
    
    /**
     * Yardımcı fonksiyonlar
     */
    private function generateSyncKey($item) {
        return hash('sha256', $item['id'] . $item['operation_type'] . $this->storeId . $item['created_at']);
    }
    
    private function generateWebhookSignature($data) {
        return hash_hmac('sha256', json_encode($data), 'pos_webhook_secret_key');
    }
    
    private function ensureLogDirectory() {
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] $message\n";
        
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
        
        // CLI'de de göster
        echo $logEntry;
    }
}

// Script başlat
try {
    $syncManager = new AutoSyncManager($conn);
    $syncManager->run();
    echo "✅ AutoSync tamamlandı\n";
} catch (Exception $e) {
    echo "❌ AutoSync hatası: " . $e->getMessage() . "\n";
    exit(1);
}