<?php
/**
 * MERKEZ MAĞAZA - SYNC DURUM KONTROLÜ API'Sİ
 * Senkronizasyon durumunu izler ve raporlar
 * Hibrit Sync - Phase 1: Health monitoring and status reporting
 */

require_once '../../session_manager.php';
require_once '../../db_connection.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Güvenlik kontrolü - sadece admin erişimi veya cron
$is_cron = (php_sapi_name() === 'cli' || isset($_GET['cron_key']));
$is_admin = (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true);

if (!$is_cron && !$is_admin) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Yetkisiz erişim']));
}

// Konfigürasyon
$STORE_ID = 1; // Merkez mağaza
$MAIN_SERVER_URL = 'https://pos.incikirtasiye.com/admin/api/sync/sync_status.php';
$API_KEY = 'merkez_sync_key_2025';
$CRITICAL_DELAY_MINUTES = 60; // 1 saat gecikmeli sync kritik kabul edilir
$WARNING_DELAY_MINUTES = 30; // 30 dakika gecikmeli sync uyarı

$response = [
    'success' => false,
    'message' => '',
    'sync_status' => 'unknown',
    'local_stats' => [],
    'remote_stats' => [],
    'health_check' => [],
    'recommendations' => [],
    'alerts' => []
];

try {
    $check_type = $_GET['type'] ?? 'full';
    
    switch ($check_type) {
        case 'full':
            $result = performFullHealthCheck();
            break;
            
        case 'quick':
            $result = performQuickHealthCheck();
            break;
            
        case 'queue_only':
            $result = checkQueueHealth();
            break;
            
        case 'connectivity':
            $result = checkConnectivity();
            break;
            
        case 'performance':
            $result = checkPerformance();
            break;
            
        case 'data_integrity':
            $result = checkDataIntegrity();
            break;
            
        default:
            throw new Exception("Desteklenmeyen kontrol tipi: $check_type");
    }
    
    $response = array_merge($response, $result);
    $response['success'] = true;
    
    // Health check sonucunu logla
    logHealthCheckResult($check_type, $response);
    
} catch (Exception $e) {
    $response['message'] = 'Health Check Error: ' . $e->getMessage();
    error_log('Sync Health Check Error: ' . $e->getMessage());
    http_response_code(500);
}

echo json_encode($response);

/**
 * Tam sağlık kontrolü
 */
function performFullHealthCheck() {
    $result = [
        'message' => 'Tam sağlık kontrolü tamamlandı',
        'sync_status' => 'healthy',
        'local_stats' => [],
        'remote_stats' => [],
        'health_check' => [],
        'recommendations' => [],
        'alerts' => []
    ];
    
    // 1. Local istatistikleri topla
    $result['local_stats'] = getLocalSyncStats();
    
    // 2. Kuyruk sağlığını kontrol et
    $queue_health = checkQueueHealth();
    $result['health_check']['queue'] = $queue_health;
    
    // 3. Bağlantı kontrolü
    $connectivity = checkConnectivity();
    $result['health_check']['connectivity'] = $connectivity;
    
    // 4. Performans kontrolü
    $performance = checkPerformance();
    $result['health_check']['performance'] = $performance;
    
    // 5. Veri bütünlüğü kontrolü
    $data_integrity = checkDataIntegrity();
    $result['health_check']['data_integrity'] = $data_integrity;
    
    // 6. Ana sunucudan durum al
    try {
        $result['remote_stats'] = getRemoteStats();
    } catch (Exception $e) {
        $result['alerts'][] = [
            'type' => 'warning',
            'message' => 'Ana sunucudan durum alınamadı: ' . $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    // 7. Genel durumu değerlendir
    $overall_status = evaluateOverallHealth($result['health_check']);
    $result['sync_status'] = $overall_status['status'];
    $result['alerts'] = array_merge($result['alerts'], $overall_status['alerts']);
    $result['recommendations'] = $overall_status['recommendations'];
    
    return $result;
}

/**
 * Hızlı sağlık kontrolü
 */
function performQuickHealthCheck() {
    $result = [
        'message' => 'Hızlı sağlık kontrolü tamamlandı',
        'sync_status' => 'healthy',
        'local_stats' => [],
        'health_check' => [],
        'alerts' => []
    ];
    
    // Sadece kritik kontroller
    $result['local_stats'] = getBasicLocalStats();
    $result['health_check']['queue'] = checkQueueHealthQuick();
    $result['health_check']['last_sync'] = checkLastSyncTime();
    
    // Durum değerlendirme
    $has_critical_issues = false;
    
    if ($result['health_check']['queue']['status'] === 'critical') {
        $has_critical_issues = true;
        $result['alerts'][] = [
            'type' => 'critical',
            'message' => 'Kuyrukta kritik sorunlar var',
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    if ($result['health_check']['last_sync']['minutes_ago'] > 60) {
        $has_critical_issues = true;
        $result['alerts'][] = [
            'type' => 'critical',
            'message' => 'Son sync 1 saatten eski',
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    $result['sync_status'] = $has_critical_issues ? 'critical' : 'healthy';
    
    return $result;
}

/**
 * Local sync istatistiklerini al
 */
function getLocalSyncStats() {
    global $conn, $STORE_ID;
    
    try {
        // Bugünkü sync istatistikleri
        $stmt = $conn->prepare("
            SELECT 
                total_operations,
                successful_operations,
                failed_operations,
                last_sync_time,
                ROUND(
                    (successful_operations / NULLIF(total_operations, 0)) * 100, 2
                ) as success_rate
            FROM sync_stats 
            WHERE magaza_id = :magaza_id 
                AND stat_date = CURDATE()
        ");
        
        $stmt->execute([':magaza_id' => $STORE_ID]);
        $today_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Son 7 günün ortalaması
        $stmt2 = $conn->prepare("
            SELECT 
                AVG(total_operations) as avg_operations,
                AVG(successful_operations) as avg_successful,
                AVG(failed_operations) as avg_failed,
                AVG(
                    (successful_operations / NULLIF(total_operations, 0)) * 100
                ) as avg_success_rate
            FROM sync_stats 
            WHERE magaza_id = :magaza_id 
                AND stat_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ");
        
        $stmt2->execute([':magaza_id' => $STORE_ID]);
        $weekly_avg = $stmt2->fetch(PDO::FETCH_ASSOC);
        
        // Bekleyen satışlar
        $stmt3 = $conn->prepare("
            SELECT COUNT(*) as pending_sales
            FROM satis_faturalari 
            WHERE magaza = :magaza_id 
                AND (sync_durumu = 0 OR sync_durumu IS NULL)
                AND fatura_tarihi >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        
        $stmt3->execute([':magaza_id' => $STORE_ID]);
        $pending_sales = $stmt3->fetchColumn();
        
        return [
            'today' => $today_stats ?: [
                'total_operations' => 0,
                'successful_operations' => 0,
                'failed_operations' => 0,
                'success_rate' => 100,
                'last_sync_time' => null
            ],
            'weekly_average' => $weekly_avg ?: [
                'avg_operations' => 0,
                'avg_successful' => 0,
                'avg_failed' => 0,
                'avg_success_rate' => 100
            ],
            'pending_sales' => $pending_sales,
            'store_id' => $STORE_ID,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
    } catch (Exception $e) {
        throw new Exception('getLocalSyncStats error: ' . $e->getMessage());
    }
}

/**
 * Temel local istatistikler (hızlı kontrol için)
 */
function getBasicLocalStats() {
    global $conn, $STORE_ID;
    
    try {
        $stmt = $conn->prepare("
            SELECT 
                COUNT(CASE WHEN sync_durumu = 0 OR sync_durumu IS NULL THEN 1 END) as pending_sales,
                COUNT(CASE WHEN sync_durumu = 1 THEN 1 END) as synced_sales,
                MAX(sync_tarihi) as last_sync_time
            FROM satis_faturalari 
            WHERE magaza = :magaza_id 
                AND fatura_tarihi >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        
        $stmt->execute([':magaza_id' => $STORE_ID]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Kuyruk sağlığını kontrol et
 */
function checkQueueHealth() {
    global $conn, $STORE_ID, $CRITICAL_DELAY_MINUTES;
    
    try {
        // Kuyruk istatistikleri
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_items,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                MIN(created_at) as oldest_pending,
                AVG(attempts) as avg_attempts
            FROM sync_queue 
            WHERE magaza_id = :magaza_id
                AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        
        $stmt->execute([':magaza_id' => $STORE_ID]);
        $queue_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Kritik gecikmiş öğeler
        $stmt2 = $conn->prepare("
            SELECT COUNT(*) as critical_delayed
            FROM sync_queue 
            WHERE magaza_id = :magaza_id
                AND status IN ('pending', 'failed')
                AND created_at < DATE_SUB(NOW(), INTERVAL :critical_minutes MINUTE)
        ");
        
        $stmt2->execute([
            ':magaza_id' => $STORE_ID,
            ':critical_minutes' => $CRITICAL_DELAY_MINUTES
        ]);
        
        $critical_delayed = $stmt2->fetchColumn();
        
        // Durum değerlendirme
        $status = 'healthy';
        $issues = [];
        
        if ($critical_delayed > 0) {
            $status = 'critical';
            $issues[] = "$critical_delayed adet kritik gecikmiş öğe var";
        } elseif ($queue_stats['failed'] > 10) {
            $status = 'warning';
            $issues[] = "Çok sayıda başarısız öğe: {$queue_stats['failed']}";
        } elseif ($queue_stats['pending'] > 50) {
            $status = 'warning';
            $issues[] = "Çok sayıda bekleyen öğe: {$queue_stats['pending']}";
        }
        
        return [
            'status' => $status,
            'statistics' => $queue_stats,
            'critical_delayed' => $critical_delayed,
            'issues' => $issues,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Hızlı kuyruk sağlık kontrolü
 */
function checkQueueHealthQuick() {
    global $conn, $STORE_ID;
    
    try {
        $stmt = $conn->prepare("
            SELECT 
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed
            FROM sync_queue 
            WHERE magaza_id = :magaza_id
                AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        
        $stmt->execute([':magaza_id' => $STORE_ID]);
        $quick_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $status = 'healthy';
        if ($quick_stats['failed'] > 5) {
            $status = 'critical';
        } elseif ($quick_stats['pending'] > 20) {
            $status = 'warning';
        }
        
        return [
            'status' => $status,
            'pending' => $quick_stats['pending'],
            'failed' => $quick_stats['failed']
        ];
        
    } catch (Exception $e) {
        return ['status' => 'error', 'error' => $e->getMessage()];
    }
}

/**
 * Son sync zamanını kontrol et
 */
function checkLastSyncTime() {
    global $conn, $STORE_ID;
    
    try {
        $stmt = $conn->prepare("
            SELECT 
                MAX(sync_tarihi) as last_sync,
                TIMESTAMPDIFF(MINUTE, MAX(sync_tarihi), NOW()) as minutes_ago
            FROM satis_faturalari 
            WHERE magaza = :magaza_id 
                AND sync_durumu = 1
        ");
        
        $stmt->execute([':magaza_id' => $STORE_ID]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'last_sync' => $result['last_sync'],
            'minutes_ago' => $result['minutes_ago'] ?: 0,
            'status' => ($result['minutes_ago'] ?: 0) > 60 ? 'warning' : 'healthy'
        ];
        
    } catch (Exception $e) {
        return ['status' => 'error', 'error' => $e->getMessage()];
    }
}

/**
 * Bağlantı kontrolü
 */
function checkConnectivity() {
    global $MAIN_SERVER_URL, $API_KEY, $STORE_ID;
    
    $result = [
        'status' => 'healthy',
        'response_time' => null,
        'server_reachable' => false,
        'api_accessible' => false,
        'issues' => []
    ];
    
    try {
        $start_time = microtime(true);
        
        // Basit ping testi
        $ping_url = str_replace('/admin/api/sync/sync_status.php', '/ping.php', $MAIN_SERVER_URL);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $ping_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false
        ]);
        
        $ping_response = curl_exec($ch);
        $ping_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ping_error = curl_error($ch);
        curl_close($ch);
        
        $response_time = round((microtime(true) - $start_time) * 1000, 2);
        $result['response_time'] = $response_time;
        
        if ($ping_error) {
            $result['status'] = 'critical';
            $result['issues'][] = 'Sunucuya bağlantı hatası: ' . $ping_error;
            return $result;
        }
        
        if ($ping_code === 200) {
            $result['server_reachable'] = true;
        } else {
            $result['issues'][] = "Sunucu yanıt kodu: $ping_code";
        }
        
        // API erişim testi
        $api_test_data = [
            'test' => true,
            'store_id' => $STORE_ID,
            'timestamp' => time()
        ];
        
        $timestamp = time();
        $message = $STORE_ID . serialize($api_test_data) . $timestamp;
        $signature = hash_hmac('sha256', $message, $API_KEY);
        
        $headers = [
            'Content-Type: application/json',
            'X-Store-ID: ' . $STORE_ID,
            'X-Timestamp: ' . $timestamp,
            'X-Signature: ' . $signature,
            'X-API-Key: ' . $API_KEY
        ];
        
        $ch2 = curl_init();
        curl_setopt_array($ch2, [
            CURLOPT_URL => $MAIN_SERVER_URL,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($api_test_data),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $api_response = curl_exec($ch2);
        $api_code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);
        
        if ($api_code === 200) {
            $result['api_accessible'] = true;
        } else {
            $result['issues'][] = "API erişim sorunu. HTTP kod: $api_code";
        }
        
        // Genel durum değerlendirme
        if (!$result['server_reachable']) {
            $result['status'] = 'critical';
        } elseif (!$result['api_accessible']) {
            $result['status'] = 'warning';
        } elseif ($response_time > 5000) {
            $result['status'] = 'warning';
            $result['issues'][] = 'Yavaş yanıt süresi: ' . $response_time . 'ms';
        }
        
    } catch (Exception $e) {
        $result['status'] = 'error';
        $result['issues'][] = 'Bağlantı testi hatası: ' . $e->getMessage();
    }
    
    return $result;
}

/**
 * Performans kontrolü
 */
function checkPerformance() {
    global $conn, $STORE_ID;
    
    $result = [
        'status' => 'healthy',
        'database_performance' => [],
        'sync_performance' => [],
        'issues' => []
    ];
    
    try {
        // Veritabanı performansı
        $db_start = microtime(true);
        
        $stmt = $conn->prepare("
            SELECT COUNT(*) as total_records
            FROM satis_faturalari 
            WHERE magaza = :magaza_id
        ");
        $stmt->execute([':magaza_id' => $STORE_ID]);
        $total_records = $stmt->fetchColumn();
        
        $db_time = round((microtime(true) - $db_start) * 1000, 2);
        
        $result['database_performance'] = [
            'query_time_ms' => $db_time,
            'total_records' => $total_records,
            'status' => $db_time > 1000 ? 'slow' : 'fast'
        ];
        
        // Sync performansı
        $stmt2 = $conn->prepare("
            SELECT 
                AVG(TIMESTAMPDIFF(SECOND, created_at, processed_at)) as avg_processing_time,
                COUNT(*) as processed_count
            FROM sync_queue 
            WHERE magaza_id = :magaza_id
                AND status = 'completed'
                AND processed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        
        $stmt2->execute([':magaza_id' => $STORE_ID]);
        $sync_perf = $stmt2->fetch(PDO::FETCH_ASSOC);
        
        $result['sync_performance'] = [
            'avg_processing_time_seconds' => round($sync_perf['avg_processing_time'] ?: 0, 2),
            'processed_last_24h' => $sync_perf['processed_count'] ?: 0
        ];
        
        // Performans değerlendirme
        if ($db_time > 2000) {
            $result['status'] = 'warning';
            $result['issues'][] = 'Veritabanı sorguları yavaş';
        }
        
        if (($sync_perf['avg_processing_time'] ?: 0) > 30) {
            $result['status'] = 'warning';
            $result['issues'][] = 'Sync işlemleri yavaş';
        }
        
    } catch (Exception $e) {
        $result['status'] = 'error';
        $result['issues'][] = 'Performans testi hatası: ' . $e->getMessage();
    }
    
    return $result;
}

/**
 * Veri bütünlüğü kontrolü
 */
function checkDataIntegrity() {
    global $conn, $STORE_ID;
    
    $result = [
        'status' => 'healthy',
        'checks' => [],
        'issues' => []
    ];
    
    try {
        // 1. Sync durumu tutarsızlığı kontrolü
        $stmt = $conn->prepare("
            SELECT COUNT(*) as inconsistent_sync
            FROM satis_faturalari 
            WHERE magaza = :magaza_id
                AND sync_durumu = 1
                AND sync_tarihi IS NULL
        ");
        $stmt->execute([':magaza_id' => $STORE_ID]);
        $inconsistent_sync = $stmt->fetchColumn();
        
        $result['checks']['sync_consistency'] = [
            'inconsistent_records' => $inconsistent_sync,
            'status' => $inconsistent_sync > 0 ? 'failed' : 'passed'
        ];
        
        if ($inconsistent_sync > 0) {
            $result['issues'][] = "$inconsistent_sync adet sync durumu tutarsız kayıt";
        }
        
        // 2. Eksik fatura detayları kontrolü
        $stmt2 = $conn->prepare("
            SELECT COUNT(*) as missing_details
            FROM satis_faturalari sf
            LEFT JOIN satis_fatura_detay sfd ON sf.id = sfd.fatura_id
            WHERE sf.magaza = :magaza_id
                AND sf.fatura_tarihi >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                AND sfd.fatura_id IS NULL
        ");
        $stmt2->execute([':magaza_id' => $STORE_ID]);
        $missing_details = $stmt2->fetchColumn();
        
        $result['checks']['invoice_details'] = [
            'missing_details' => $missing_details,
            'status' => $missing_details > 0 ? 'failed' : 'passed'
        ];
        
        if ($missing_details > 0) {
            $result['issues'][] = "$missing_details adet detaysız fatura";
        }
        
        // 3. Müşteri puan tutarsızlığı kontrolü
        $stmt3 = $conn->prepare("
            SELECT COUNT(*) as orphaned_points
            FROM puan_kazanma pk
            LEFT JOIN musteriler m ON pk.musteri_id = m.id
            WHERE m.id IS NULL
        ");
        $stmt3->execute();
        $orphaned_points = $stmt3->fetchColumn();
        
        $result['checks']['customer_points'] = [
            'orphaned_records' => $orphaned_points,
            'status' => $orphaned_points > 0 ? 'warning' : 'passed'
        ];
        
        if ($orphaned_points > 0) {
            $result['issues'][] = "$orphaned_points adet sahipsiz puan kaydı";
        }
        
        // Genel durum
        $failed_checks = array_filter($result['checks'], function($check) {
            return $check['status'] === 'failed';
        });
        
        if (count($failed_checks) > 0) {
            $result['status'] = 'warning';
        }
        
    } catch (Exception $e) {
        $result['status'] = 'error';
        $result['issues'][] = 'Veri bütünlüğü testi hatası: ' . $e->getMessage();
    }
    
    return $result;
}

/**
 * Ana sunucudan durum bilgilerini al
 */
function getRemoteStats() {
    global $MAIN_SERVER_URL, $API_KEY, $STORE_ID;
    
    try {
        $request_data = [
            'action' => 'get_store_status',
            'store_id' => $STORE_ID
        ];
        
        $timestamp = time();
        $message = $STORE_ID . serialize($request_data) . $timestamp;
        $signature = hash_hmac('sha256', $message, $API_KEY);
        
        $headers = [
            'Content-Type: application/json',
            'X-Store-ID: ' . $STORE_ID,
            'X-Timestamp: ' . $timestamp,
            'X-Signature: ' . $signature,
            'X-API-Key: ' . $API_KEY
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $MAIN_SERVER_URL,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($request_data),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($curl_error) {
            throw new Exception('cURL Error: ' . $curl_error);
        }
        
        if ($http_code !== 200) {
            throw new Exception("HTTP Error: $http_code");
        }
        
        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response');
        }
        
        return $result['data'] ?? [];
        
    } catch (Exception $e) {
        throw new Exception('Remote stats error: ' . $e->getMessage());
    }
}

/**
 * Genel sağlık durumunu değerlendir
 */
function evaluateOverallHealth($health_checks) {
    $alerts = [];
    $recommendations = [];
    $status = 'healthy';
    
    // Kritik kontroller
    foreach ($health_checks as $check_name => $check_result) {
        if (isset($check_result['status'])) {
            if ($check_result['status'] === 'critical') {
                $status = 'critical';
                $alerts[] = [
                    'type' => 'critical',
                    'source' => $check_name,
                    'message' => implode(', ', $check_result['issues'] ?? ['Kritik sorun tespit edildi']),
                    'timestamp' => date('Y-m-d H:i:s')
                ];
            } elseif ($check_result['status'] === 'warning' && $status !== 'critical') {
                $status = 'warning';
                $alerts[] = [
                    'type' => 'warning',
                    'source' => $check_name,
                    'message' => implode(', ', $check_result['issues'] ?? ['Uyarı durumu']),
                    'timestamp' => date('Y-m-d H:i:s')
                ];
            }
        }
    }
    
    // Öneriler oluştur
    if (isset($health_checks['queue']['failed']) && $health_checks['queue']['failed'] > 5) {
        $recommendations[] = 'Başarısız kuyruk öğelerini manuel olarak tekrar deneyin';
    }
    
    if (isset($health_checks['connectivity']['response_time']) && $health_checks['connectivity']['response_time'] > 3000) {
        $recommendations[] = 'Ağ bağlantısını kontrol edin, yavaş yanıt süreleri tespit edildi';
    }
    
    if (isset($health_checks['performance']['database_performance']['query_time_ms']) && 
        $health_checks['performance']['database_performance']['query_time_ms'] > 1000) {
        $recommendations[] = 'Veritabanı performansını optimize edin';
    }
    
    return [
        'status' => $status,
        'alerts' => $alerts,
        'recommendations' => $recommendations
    ];
}

/**
 * Health check sonucunu logla
 */
function logHealthCheckResult($check_type, $result) {
    global $conn, $STORE_ID;
    
    try {
        $stmt = $conn->prepare("
            INSERT INTO sync_queue (
                magaza_id, operation_type, table_name,
                data_json, status, created_at, processed_at
            ) VALUES (
                :magaza_id, 'health_check', :check_type,
                :result_json, 'completed', NOW(), NOW()
            )
        ");
        
        $stmt->execute([
            ':magaza_id' => $STORE_ID,
            ':check_type' => $check_type,
            ':result_json' => json_encode([
                'status' => $result['sync_status'],
                'alerts_count' => count($result['alerts'] ?? []),
                'recommendations_count' => count($result['recommendations'] ?? [])
            ])
        ]);
        
    } catch (Exception $e) {
        error_log('Health check logging failed: ' . $e->getMessage());
    }
}
?>