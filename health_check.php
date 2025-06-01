<?php
/**
 * Sistem Sağlık Kontrolü Cron Job
 * ==============================
 * Her dakika çalışır: * * * * * /usr/bin/php /path/to/cron/health_check.php
 * 
 * İşlevler:
 * - Sistem kaynaklarını kontrol eder
 * - Veritabanı performansını izler
 * - API erişilebilirliğini test eder
 * - Kritik servislerin durumunu kontrol eder
 * - Sorun tespit ettiğinde alarm verir
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
set_time_limit(60); // 1 dakika timeout

class SystemHealthChecker {
    private $conn;
    private $storeId;
    private $syncMode;
    private $logFile;
    private $alertFile;
    private $config;
    private $healthStatus;
    
    public function __construct($conn) {
        $this->conn = $conn;
        $this->loadConfig();
        $this->logFile = dirname(__DIR__) . '/logs/health_' . date('Y-m-d') . '.log';
        $this->alertFile = dirname(__DIR__) . '/logs/alerts_' . date('Y-m-d') . '.log';
        $this->ensureLogDirectory();
        $this->healthStatus = [];
        
        $this->log("🏥 Health Check başlatıldı - Mağaza: {$this->storeId}");
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
        
        // Health check ayarları
        $this->config = [
            // Eşik değerler
            'cpu_threshold' => 80,          // %80 CPU kullanımı
            'memory_threshold' => 80,       // %80 RAM kullanımı
            'disk_threshold' => 90,         // %90 disk kullanımı
            'response_time_threshold' => 5000, // 5 saniye
            'queue_threshold' => 200,       // 200 bekleyen işlem
            'failed_sync_threshold' => 20,  // 20 başarısız sync
            
            // Alert ayarları
            'alert_cooldown' => 300,        // 5 dakika alert cooldown
            'max_alerts_per_hour' => 12,    // Saatte maksimum 12 alert
            'critical_checks' => ['database', 'api', 'disk_space'],
            
            // Monitoring
            'keep_history_days' => 30,      // 30 gün geçmiş saklama
            'sample_interval' => 60         // 60 saniye örnekleme
        ];
    }
    
    /**
     * Ana health check işlemini çalıştır
     */
    public function run() {
        try {
            $startTime = microtime(true);
            
            // Tüm kontrolleri gerçekleştir
            $this->healthStatus = [
                'timestamp' => date('c'),
                'store_id' => $this->storeId,
                'sync_mode' => $this->syncMode,
                'checks' => [
                    'system_resources' => $this->checkSystemResources(),
                    'database' => $this->checkDatabaseHealth(),
                    'api_connectivity' => $this->checkAPIHealth(),
                    'sync_queue' => $this->checkSyncQueueHealth(),
                    'disk_space' => $this->checkDiskSpace(),
                    'network' => $this->checkNetworkHealth(),
                    'services' => $this->checkCriticalServices(),
                    'performance' => $this->checkPerformanceMetrics()
                ]
            ];
            
            $duration = round((microtime(true) - $startTime) * 1000);
            $this->healthStatus['check_duration_ms'] = $duration;
            
            // Genel sağlık skorunu hesapla
            $overallHealth = $this->calculateOverallHealth();
            $this->healthStatus['overall_health'] = $overallHealth;
            
            // Sonuçları kaydet
            $this->saveHealthResults();
            
            // Alert kontrolü
            $this->checkForAlerts();
            
            // Performans metriklerini kaydet
            $this->savePerformanceMetrics();
            
            $this->log("✅ Health check tamamlandı ({$duration}ms) - Skor: {$overallHealth['score']}/100");
            
        } catch (Exception $e) {
            $this->log("❌ Health check hatası: " . $e->getMessage());
            $this->sendCriticalAlert('Health Check Failure', $e->getMessage());
        }
    }
    
    /**
     * Sistem kaynaklarını kontrol et
     */
    private function checkSystemResources() {
        try {
            $result = [
                'status' => 'ok',
                'details' => []
            ];
            
            // CPU kullanımı (Linux)
            if (function_exists('sys_getloadavg')) {
                $load = sys_getloadavg();
                $cpuUsage = $load[0] * 100 / 4; // 4 çekirdek varsayılıyor
                
                $result['details']['cpu_usage'] = round($cpuUsage, 2);
                
                if ($cpuUsage > $this->config['cpu_threshold']) {
                    $result['status'] = 'warning';
                    $result['issues'][] = "Yüksek CPU kullanımı: %{$cpuUsage}";
                }
            }
            
            // Bellek kullanımı
            $memInfo = $this->getMemoryInfo();
            if ($memInfo) {
                $memUsage = (($memInfo['total'] - $memInfo['available']) / $memInfo['total']) * 100;
                $result['details']['memory_usage'] = round($memUsage, 2);
                $result['details']['memory_total_mb'] = round($memInfo['total'] / 1024 / 1024);
                $result['details']['memory_available_mb'] = round($memInfo['available'] / 1024 / 1024);
                
                if ($memUsage > $this->config['memory_threshold']) {
                    $result['status'] = 'warning';
                    $result['issues'][] = "Yüksek bellek kullanımı: %{$memUsage}";
                }
            }
            
            // PHP bellek kullanımı
            $phpMemory = memory_get_usage(true);
            $phpMemoryPeak = memory_get_peak_usage(true);
            $result['details']['php_memory_mb'] = round($phpMemory / 1024 / 1024, 2);
            $result['details']['php_memory_peak_mb'] = round($phpMemoryPeak / 1024 / 1024, 2);
            
            return $result;
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Sistem kaynakları kontrol hatası: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Veritabanı sağlığını kontrol et
     */
    private function checkDatabaseHealth() {
        try {
            $startTime = microtime(true);
            
            // Basit bağlantı testi
            $stmt = $this->conn->query("SELECT 1");
            $connectionTime = round((microtime(true) - $startTime) * 1000);
            
            $result = [
                'status' => 'ok',
                'details' => [
                    'connection_time_ms' => $connectionTime
                ]
            ];
            
            // Slow query testi
            $startTime = microtime(true);
            $stmt = $this->conn->query("SELECT COUNT(*) FROM satis_faturalari WHERE DATE(fatura_tarihi) = CURDATE()");
            $queryTime = round((microtime(true) - $startTime) * 1000);
            $result['details']['query_time_ms'] = $queryTime;
            
            // Performans metrikleri
            $stmt = $this->conn->query("SHOW STATUS LIKE 'Threads_connected'");
            $connections = $stmt->fetch(PDO::FETCH_ASSOC);
            $result['details']['active_connections'] = (int)$connections['Value'];
            
            // Tablo boyutları
            $stmt = $this->conn->query("
                SELECT 
                    table_name,
                    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
                FROM information_schema.tables 
                WHERE table_schema = DATABASE()
                ORDER BY (data_length + index_length) DESC
                LIMIT 5
            ");
            $result['details']['largest_tables'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Deadlock kontrolü
            $stmt = $this->conn->query("SHOW ENGINE INNODB STATUS");
            $innodbStatus = $stmt->fetch(PDO::FETCH_ASSOC);
            if (strpos($innodbStatus['Status'], 'DEADLOCK') !== false) {
                $result['status'] = 'warning';
                $result['issues'][] = 'Deadlock tespit edildi';
            }
            
            // Yavaş sorgu uyarısı
            if ($queryTime > $this->config['response_time_threshold']) {
                $result['status'] = 'warning';
                $result['issues'][] = "Yavaş sorgu: {$queryTime}ms";
            }
            
            return $result;
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Veritabanı kontrol hatası: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * API sağlığını kontrol et
     */
    private function checkAPIHealth() {
        try {
            $result = [
                'status' => 'ok',
                'details' => []
            ];
            
            $endpoints = [
                'main_server' => 'https://pos.incikirtasiye.com/admin/api/sync/sync_status.php',
                'local_sync' => '/admin/api/local_sync/sync_status_check.php'
            ];
            
            foreach ($endpoints as $name => $url) {
                $startTime = microtime(true);
                
                try {
                    if (strpos($url, 'http') === 0) {
                        // External API
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
                        
                        $responseTime = round((microtime(true) - $startTime) * 1000);
                        
                        $result['details'][$name] = [
                            'response_time_ms' => $responseTime,
                            'http_code' => $httpCode,
                            'status' => $httpCode === 200 ? 'ok' : 'error'
                        ];
                        
                        if ($httpCode !== 200) {
                            $result['status'] = 'warning';
                            $result['issues'][] = "$name API yanıt vermiyor (HTTP $httpCode)";
                        }
                        
                    } else {
                        // Local API
                        $fullPath = dirname(__DIR__) . $url;
                        if (file_exists($fullPath)) {
                            $result['details'][$name] = [
                                'status' => 'ok',
                                'file_exists' => true
                            ];
                        } else {
                            $result['details'][$name] = [
                                'status' => 'error',
                                'file_exists' => false
                            ];
                            $result['status'] = 'warning';
                            $result['issues'][] = "$name API dosyası bulunamadı";
                        }
                    }
                    
                } catch (Exception $e) {
                    $result['details'][$name] = [
                        'status' => 'error',
                        'error' => $e->getMessage()
                    ];
                    $result['status'] = 'warning';
                    $result['issues'][] = "$name API hatası: " . $e->getMessage();
                }
            }
            
            return $result;
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'API kontrol hatası: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Sync kuyruk sağlığını kontrol et
     */
    private function checkSyncQueueHealth() {
        try {
            $stmt = $this->conn->prepare("
                SELECT 
                    status,
                    COUNT(*) as count,
                    AVG(attempts) as avg_attempts,
                    MAX(attempts) as max_attempts,
                    MIN(created_at) as oldest_item
                FROM sync_queue 
                WHERE magaza_id = ?
                GROUP BY status
            ");
            $stmt->execute([$this->storeId]);
            $queueStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $result = [
                'status' => 'ok',
                'details' => [
                    'queue_stats' => $queueStats
                ]
            ];
            
            $totalPending = 0;
            $totalFailed = 0;
            
            foreach ($queueStats as $stat) {
                if ($stat['status'] === 'pending') {
                    $totalPending = $stat['count'];
                }
                if ($stat['status'] === 'failed') {
                    $totalFailed = $stat['count'];
                }
            }
            
            $result['details']['total_pending'] = $totalPending;
            $result['details']['total_failed'] = $totalFailed;
            
            // Kritik eşik kontrolleri
            if ($totalPending > $this->config['queue_threshold']) {
                $result['status'] = 'warning';
                $result['issues'][] = "Çok fazla bekleyen işlem: $totalPending";
            }
            
            if ($totalFailed > $this->config['failed_sync_threshold']) {
                $result['status'] = 'warning';
                $result['issues'][] = "Çok fazla başarısız işlem: $totalFailed";
            }
            
            // Eski işlemler kontrolü
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) as old_items
                FROM sync_queue 
                WHERE magaza_id = ? 
                AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
                AND status = 'pending'
            ");
            $stmt->execute([$this->storeId]);
            $oldItems = $stmt->fetchColumn();
            
            if ($oldItems > 10) {
                $result['status'] = 'warning';
                $result['issues'][] = "1 saatten eski bekleyen işlemler: $oldItems";
            }
            
            return $result;
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Sync kuyruk kontrol hatası: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Disk alanını kontrol et
     */
    private function checkDiskSpace() {
        try {
            $path = dirname(__DIR__);
            $totalBytes = disk_total_space($path);
            $freeBytes = disk_free_space($path);
            $usedBytes = $totalBytes - $freeBytes;
            $usagePercent = ($usedBytes / $totalBytes) * 100;
            
            $result = [
                'status' => 'ok',
                'details' => [
                    'total_gb' => round($totalBytes / 1024 / 1024 / 1024, 2),
                    'free_gb' => round($freeBytes / 1024 / 1024 / 1024, 2),
                    'used_gb' => round($usedBytes / 1024 / 1024 / 1024, 2),
                    'usage_percent' => round($usagePercent, 2)
                ]
            ];
            
            if ($usagePercent > $this->config['disk_threshold']) {
                $result['status'] = 'error';
                $result['issues'][] = "Kritik disk kullanımı: %{$usagePercent}";
            } elseif ($usagePercent > ($this->config['disk_threshold'] - 10)) {
                $result['status'] = 'warning';
                $result['issues'][] = "Yüksek disk kullanımı: %{$usagePercent}";
            }
            
            // Log dosya boyutları
            $logDir = dirname(__DIR__) . '/logs';
            if (is_dir($logDir)) {
                $logSize = $this->getDirectorySize($logDir);
                $result['details']['log_size_mb'] = round($logSize / 1024 / 1024, 2);
                
                if ($logSize > 500 * 1024 * 1024) { // 500MB
                    $result['status'] = 'warning';
                    $result['issues'][] = "Log dosyaları çok büyük: " . round($logSize / 1024 / 1024) . "MB";
                }
            }
            
            return $result;
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Disk alanı kontrol hatası: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Ağ bağlantısını kontrol et
     */
    private function checkNetworkHealth() {
        try {
            $result = [
                'status' => 'ok',
                'details' => []
            ];
            
            // İnternet bağlantısı testi
            $hosts = [
                'google' => 'google.com',
                'main_server' => 'pos.incikirtasiye.com'
            ];
            
            foreach ($hosts as $name => $host) {
                $startTime = microtime(true);
                $socket = @fsockopen($host, 80, $errno, $errstr, 5);
                $responseTime = round((microtime(true) - $startTime) * 1000);
                
                if ($socket) {
                    fclose($socket);
                    $result['details'][$name] = [
                        'status' => 'ok',
                        'response_time_ms' => $responseTime
                    ];
                } else {
                    $result['details'][$name] = [
                        'status' => 'error',
                        'error' => "$errno: $errstr"
                    ];
                    $result['status'] = 'warning';
                    $result['issues'][] = "$name bağlantı hatası";
                }
            }
            
            return $result;
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Ağ kontrol hatası: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Kritik servisleri kontrol et
     */
    private function checkCriticalServices() {
        try {
            $result = [
                'status' => 'ok',
                'details' => []
            ];
            
            // Web server (Apache/Nginx)
            $webServer = $this->checkWebServer();
            $result['details']['web_server'] = $webServer;
            
            // PHP-FPM
            $phpFpm = $this->checkPHPFPM();
            $result['details']['php_fpm'] = $phpFpm;
            
            // MySQL
            $mysql = $this->checkMySQLProcess();
            $result['details']['mysql'] = $mysql;
            
            // Herhangi bir kritik servis çalışmıyorsa
            foreach ($result['details'] as $service => $status) {
                if (isset($status['status']) && $status['status'] === 'error') {
                    $result['status'] = 'error';
                    $result['issues'][] = "$service servisi çalışmıyor";
                }
            }
            
            return $result;
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Servis kontrol hatası: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Performans metriklerini kontrol et
     */
    private function checkPerformanceMetrics() {
        try {
            $result = [
                'status' => 'ok',
                'details' => []
            ];
            
            // Son 1 saatteki satış performansı
            $stmt = $this->conn->prepare("
                SELECT 
                    COUNT(*) as total_sales,
                    AVG(TIMESTAMPDIFF(SECOND, fatura_tarihi, NOW())) as avg_processing_time
                FROM satis_faturalari 
                WHERE magaza = ? 
                AND fatura_tarihi > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $stmt->execute([$this->storeId]);
            $salesPerf = $stmt->fetch(PDO::FETCH_ASSOC);
            $result['details']['sales_performance'] = $salesPerf;
            
            // Sync performansı
            $stmt = $this->conn->prepare("
                SELECT 
                    AVG(avg_sync_time) as avg_sync_time,
                    SUM(successful_operations) as successful_ops,
                    SUM(failed_operations) as failed_ops
                FROM sync_stats 
                WHERE magaza_id = ? 
                AND stat_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAYS)
            ");
            $stmt->execute([$this->storeId]);
            $syncPerf = $stmt->fetch(PDO::FETCH_ASSOC);
            $result['details']['sync_performance'] = $syncPerf;
            
            // Error rate kontrolü
            $successRate = 0;
            $totalOps = $syncPerf['successful_ops'] + $syncPerf['failed_ops'];
            if ($totalOps > 0) {
                $successRate = ($syncPerf['successful_ops'] / $totalOps) * 100;
            }
            $result['details']['sync_success_rate'] = round($successRate, 2);
            
            if ($successRate < 95) { // %95'in altında
                $result['status'] = 'warning';
                $result['issues'][] = "Düşük sync başarı oranı: %{$successRate}";
            }
            
            return $result;
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Performans kontrol hatası: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Genel sağlık skorunu hesapla
     */
    private function calculateOverallHealth() {
        $scores = [];
        $criticalIssues = [];
        
        foreach ($this->healthStatus['checks'] as $checkName => $check) {
            if (isset($check['status'])) {
                switch ($check['status']) {
                    case 'ok':
                        $scores[] = 100;
                        break;
                    case 'warning':
                        $scores[] = 70;
                        break;
                    case 'error':
                        $scores[] = 0;
                        if (in_array($checkName, $this->config['critical_checks'])) {
                            $criticalIssues[] = $checkName;
                        }
                        break;
                }
            }
        }
        
        $averageScore = empty($scores) ? 0 : round(array_sum($scores) / count($scores));
        
        return [
            'score' => $averageScore,
            'status' => $this->getHealthStatus($averageScore),
            'critical_issues' => $criticalIssues,
            'total_checks' => count($scores)
        ];
    }
    
    /**
     * Skor'a göre health status döndür
     */
    private function getHealthStatus($score) {
        if ($score >= 90) return 'excellent';
        if ($score >= 75) return 'good';
        if ($score >= 50) return 'warning';
        return 'critical';
    }
    
    /**
     * Health sonuçlarını kaydet
     */
    private function saveHealthResults() {
        try {
            // JSON formatında kaydet
            $healthFile = dirname(__DIR__) . '/logs/health_status.json';
            file_put_contents($healthFile, json_encode($this->healthStatus, JSON_PRETTY_PRINT));
            
            // Veritabanına özet kaydet
            $stmt = $this->conn->prepare("
                INSERT INTO sync_metadata (magaza_id, tablo_adi, son_sync_tarihi, sync_durumu, operation_count, last_error)
                VALUES (?, 'health_check', NOW(), ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    son_sync_tarihi = NOW(),
                    sync_durumu = VALUES(sync_durumu),
                    operation_count = VALUES(operation_count),
                    last_error = VALUES(last_error)
            ");
            
            $overallHealth = $this->healthStatus['overall_health'];
            $errors = [];
            
            foreach ($this->healthStatus['checks'] as $checkName => $check) {
                if (isset($check['issues'])) {
                    $errors = array_merge($errors, $check['issues']);
                }
            }
            
            $stmt->execute([
                $this->storeId,
                $overallHealth['status'],
                $overallHealth['score'],
                empty($errors) ? null : implode('; ', array_slice($errors, 0, 3))
            ]);
            
        } catch (Exception $e) {
            $this->log("❌ Health sonuçları kaydetme hatası: " . $e->getMessage());
        }
    }
    
    /**
     * Alert kontrolü yap
     */
    private function checkForAlerts() {
        $overallHealth = $this->healthStatus['overall_health'];
        
        // Kritik sorunlar için alert
        if (!empty($overallHealth['critical_issues'])) {
            $this->sendCriticalAlert(
                'Kritik Sistem Sorunu',
                'Kritik servisler: ' . implode(', ', $overallHealth['critical_issues'])
            );
        }
        
        // Düşük skor için alert
        if ($overallHealth['score'] < 50) {
            $this->sendAlert(
                'Düşük Sistem Performansı',
                "Sistem sağlık skoru: {$overallHealth['score']}/100"
            );
        }
        
        // Özel kontroller
        $this->checkSpecificAlerts();
    }
    
    /**
     * Özel alert kontrolleri
     */
    private function checkSpecificAlerts() {
        $checks = $this->healthStatus['checks'];
        
        // Disk alanı kritik
        if (isset($checks['disk_space']['details']['usage_percent'])) {
            $diskUsage = $checks['disk_space']['details']['usage_percent'];
            if ($diskUsage > 95) {
                $this->sendCriticalAlert(
                    'Kritik Disk Alanı',
                    "Disk kullanımı: %{$diskUsage}"
                );
            }
        }
        
        // Çok fazla bekleyen sync
        if (isset($checks['sync_queue']['details']['total_pending'])) {
            $pending = $checks['sync_queue']['details']['total_pending'];
            if ($pending > 500) {
                $this->sendAlert(
                    'Sync Kuyruğu Dolu',
                    "Bekleyen işlem sayısı: {$pending}"
                );
            }
        }
        
        // API bağlantı sorunu
        if (isset($checks['api_connectivity']['status']) && 
            $checks['api_connectivity']['status'] === 'error') {
            $this->sendCriticalAlert(
                'API Bağlantı Sorunu',
                'Ana sunucu API\'sine bağlanılamıyor'
            );
        }
    }
    
    /**
     * Performans metriklerini kaydet
     */
    private function savePerformanceMetrics() {
        try {
            $checks = $this->healthStatus['checks'];
            
            // Metrikleri al
            $metrics = [
                'cpu_usage' => $checks['system_resources']['details']['cpu_usage'] ?? 0,
                'memory_usage' => $checks['system_resources']['details']['memory_usage'] ?? 0,
                'disk_usage' => $checks['disk_space']['details']['usage_percent'] ?? 0,
                'db_response_time' => $checks['database']['details']['connection_time_ms'] ?? 0,
                'sync_queue_size' => $checks['sync_queue']['details']['total_pending'] ?? 0,
                'health_score' => $this->healthStatus['overall_health']['score']
            ];
            
            // Basit CSV formatında kaydet (grafikler için)
            $metricsFile = dirname(__DIR__) . '/logs/metrics_' . date('Y-m-d') . '.csv';
            $csvLine = date('Y-m-d H:i:s') . ',' . implode(',', $metrics) . "\n";
            
            // Header yoksa ekle
            if (!file_exists($metricsFile)) {
                $header = "timestamp,cpu_usage,memory_usage,disk_usage,db_response_time,sync_queue_size,health_score\n";
                file_put_contents($metricsFile, $header);
            }
            
            file_put_contents($metricsFile, $csvLine, FILE_APPEND);
            
        } catch (Exception $e) {
            $this->log("❌ Metrik kaydetme hatası: " . $e->getMessage());
        }
    }
    
    /**
     * Alert gönder
     */
    private function sendAlert($title, $message, $level = 'warning') {
        try {
            // Alert cooldown kontrolü
            if (!$this->shouldSendAlert($title, $level)) {
                return;
            }
            
            $alertData = [
                'timestamp' => date('c'),
                'store_id' => $this->storeId,
                'level' => $level,
                'title' => $title,
                'message' => $message,
                'health_score' => $this->healthStatus['overall_health']['score']
            ];
            
            // Log'a kaydet
            $this->alertLog("🚨 ALERT [$level] $title: $message");
            
            // E-mail göndermek isterseniz burada implementasyon ekleyebilirsiniz
            // $this->sendAlertEmail($alertData);
            
            // SMS göndermek isterseniz
            // $this->sendAlertSMS($alertData);
            
        } catch (Exception $e) {
            $this->log("❌ Alert gönderme hatası: " . $e->getMessage());
        }
    }
    
    /**
     * Kritik alert gönder
     */
    private function sendCriticalAlert($title, $message) {
        $this->sendAlert($title, $message, 'critical');
    }
    
    /**
     * Alert gönderilmeli mi kontrol et
     */
    private function shouldSendAlert($title, $level) {
        // Alert geçmişini kontrol et
        $alertHistoryFile = dirname(__DIR__) . '/logs/alert_history.json';
        
        $history = [];
        if (file_exists($alertHistoryFile)) {
            $history = json_decode(file_get_contents($alertHistoryFile), true) ?: [];
        }
        
        $now = time();
        $alertKey = md5($title . $level);
        
        // Son alert zamanını kontrol et
        if (isset($history[$alertKey])) {
            $timeDiff = $now - $history[$alertKey]['last_sent'];
            
            // Cooldown süresi
            $cooldown = $level === 'critical' ? 300 : 900; // 5dk/15dk
            
            if ($timeDiff < $cooldown) {
                return false; // Çok erken
            }
            
            // Saatlik limit kontrolü
            $hourlyCount = 0;
            foreach ($history as $alert) {
                if ($alert['last_sent'] > ($now - 3600)) {
                    $hourlyCount++;
                }
            }
            
            if ($hourlyCount >= $this->config['max_alerts_per_hour']) {
                return false; // Çok fazla alert
            }
        }
        
        // Alert geçmişini güncelle
        $history[$alertKey] = [
            'title' => $title,
            'level' => $level,
            'last_sent' => $now,
            'count' => ($history[$alertKey]['count'] ?? 0) + 1
        ];
        
        file_put_contents($alertHistoryFile, json_encode($history));
        
        return true;
    }
    
    /**
     * Yardımcı fonksiyonlar
     */
    private function getMemoryInfo() {
        if (!is_readable('/proc/meminfo')) {
            return null;
        }
        
        $memInfo = file_get_contents('/proc/meminfo');
        $matches = [];
        
        preg_match('/MemTotal:\s+(\d+) kB/', $memInfo, $matches);
        $total = isset($matches[1]) ? $matches[1] * 1024 : 0;
        
        preg_match('/MemAvailable:\s+(\d+) kB/', $memInfo, $matches);
        $available = isset($matches[1]) ? $matches[1] * 1024 : 0;
        
        return ['total' => $total, 'available' => $available];
    }
    
    private function getDirectorySize($dir) {
        $size = 0;
        foreach (glob(rtrim($dir, '/') . '/*', GLOB_NOSORT) as $file) {
            $size += is_file($file) ? filesize($file) : $this->getDirectorySize($file);
        }
        return $size;
    }
    
    private function checkWebServer() {
        // Apache/Nginx process kontrolü
        $output = shell_exec('ps aux | grep -E "(apache|httpd|nginx)" | grep -v grep');
        return [
            'status' => empty($output) ? 'error' : 'ok',
            'processes' => empty($output) ? 0 : substr_count($output, "\n")
        ];
    }
    
    private function checkPHPFPM() {
        $output = shell_exec('ps aux | grep "php-fpm" | grep -v grep');
        return [
            'status' => empty($output) ? 'error' : 'ok',
            'processes' => empty($output) ? 0 : substr_count($output, "\n")
        ];
    }
    
    private function checkMySQLProcess() {
        $output = shell_exec('ps aux | grep "mysql" | grep -v grep');
        return [
            'status' => empty($output) ? 'error' : 'ok',
            'processes' => empty($output) ? 0 : substr_count($output, "\n")
        ];
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
        echo $logEntry; // CLI'de göster
    }
    
    private function alertLog($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] $message\n";
        
        file_put_contents($this->alertFile, $logEntry, FILE_APPEND | LOCK_EX);
        $this->log($message);
    }
}

// Script başlat
try {
    $healthChecker = new SystemHealthChecker($conn);
    $healthChecker->run();
    echo "✅ Health check tamamlandı\n";
} catch (Exception $e) {
    echo "❌ Health check hatası: " . $e->getMessage() . "\n";
    exit(1);
}