<?php
/**
 * Ana Sunucu - Sync Durum API'si v2.1
 * Hibrit Mimari için senkronizasyon durumu, istatistikleri ve health check
 * 
 * Desteklenen Modlar:
 * - Merkez Mağaza: SYNC MOD (Local DB + Ana Sunucu Sync)
 * - Dolunay Mağaza: DIRECT MOD (Ana Sunucuya direkt bağlantı)
 */

require_once '../../session_manager.php';
require_once '../../db_connection.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

error_reporting(0);
ini_set('display_errors', 0);

$response = [
    'success' => false,
    'message' => '',
    'status' => [],
    'statistics' => [],
    'health' => []
];

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? $_POST['action'] ?? 'overview';
    
    switch ($action) {
        case 'overview':
            $response = getSyncOverview();
            break;
            
        case 'store_status':
            $store_id = $_GET['store_id'] ?? null;
            $response = getStoreStatus($store_id);
            break;
            
        case 'statistics':
            $period = $_GET['period'] ?? 'today';
            $response = getSyncStatistics($period);
            break;
            
        case 'health_check':
            $response = performHealthCheck();
            break;
            
        case 'queue_status':
            $store_id = $_GET['store_id'] ?? null;
            $response = getQueueStatus($store_id);
            break;
            
        case 'conflicts_summary':
            $response = getConflictsSummary();
            break;
            
        case 'recent_activity':
            $limit = min(100, (int)($_GET['limit'] ?? 20));
            $response = getRecentActivity($limit);
            break;
            
        case 'performance_metrics':
            $response = getPerformanceMetrics();
            break;
            
        case 'hybrid_status':
            $response = getHybridArchitectureStatus();
            break;
            
        case 'mode_transition':
            $store_id = $_GET['store_id'] ?? null;
            $new_mode = $_GET['new_mode'] ?? null;
            $response = handleModeTransition($store_id, $new_mode);
            break;
            
        default:
            throw new Exception('Desteklenmeyen action: ' . $action);
    }

} catch (Exception $e) {
    error_log('Sync status hatası: ' . $e->getMessage());
    $response['message'] = $e->getMessage();
} catch (Throwable $t) {
    error_log('Kritik hata: ' . $t->getMessage());
    $response['message'] = 'Sistem hatası: ' . $t->getMessage();
}

echo json_encode($response);

// ==================== ANA FONKSİYONLAR ====================

/**
 * Hibrit mimari genel sync durumu özeti
 */
function getSyncOverview() {
    global $conn;
    
    // Mağaza durumları ve modları
    $stores = getStoresOverview();
    
    // Hibrit mimari istatistikleri
    $sync_stores = count(array_filter($stores, function($s) { return $s['mode'] === 'SYNC'; }));
    $direct_stores = count(array_filter($stores, function($s) { return $s['mode'] === 'DIRECT'; }));
    
    $stats = [
        'total_stores' => count($stores),
        'sync_mode_stores' => $sync_stores,
        'direct_mode_stores' => $direct_stores,
        'online_stores' => count(array_filter($stores, function($s) { return $s['status'] === 'online'; })),
        'offline_stores' => count(array_filter($stores, function($s) { return $s['status'] === 'offline'; })),
        'sync_errors' => count(array_filter($stores, function($s) { return $s['status'] === 'error'; }))
    ];
    
    // Son sync zamanları (SYNC modundaki mağazalar için)
    $stmt = $conn->query("
        SELECT MIN(son_sync_tarihi) as oldest_sync,
               MAX(son_sync_tarihi) as newest_sync,
               COUNT(*) as total_syncs
        FROM sync_metadata 
        WHERE son_sync_tarihi IS NOT NULL
    ");
    $sync_times = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Bekleyen işlemler (sadece SYNC modundaki mağazalar)
    $stmt = $conn->query("
        SELECT COUNT(*) as pending_operations
        FROM sync_queue sq
        JOIN store_config sc ON sq.magaza_id = sc.magaza_id
        WHERE sq.status = 'pending' 
        AND sc.config_key = 'operation_mode' 
        AND sc.config_value = 'SYNC'
    ");
    $pending_ops = $stmt->fetchColumn() ?? 0;
    
    // Aktif çakışmalar
    $stmt = $conn->query("
        SELECT COUNT(*) as active_conflicts
        FROM conflict_log 
        WHERE resolution_type = 'pending'
    ");
    $active_conflicts = $stmt->fetchColumn();
    
    // Real-time istatistikler (son 5 dakika)
    $stmt = $conn->query("
        SELECT COUNT(*) as realtime_operations
        FROM sync_queue 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ");
    $realtime_ops = $stmt->fetchColumn() ?? 0;
    
    return [
        'success' => true,
        'message' => 'Hibrit mimari sync durumu başarıyla alındı',
        'architecture_version' => '2.1',
        'overview' => [
            'stores' => $stores,
            'statistics' => $stats,
            'sync_times' => $sync_times,
            'pending_operations' => $pending_ops,
            'active_conflicts' => $active_conflicts,
            'realtime_operations' => $realtime_ops,
            'last_update' => date('Y-m-d H:i:s')
        ]
    ];
}

/**
 * Hibrit mimari için mağaza durumları özeti
 */
function getStoresOverview() {
    global $conn;
    
    $stmt = $conn->query("
        SELECT m.id, m.ad as store_name,
               sc.config_value as operation_mode,
               sm.son_sync_tarihi as last_sync,
               sm.sync_durumu as sync_status,
               ss.last_sync_time as stats_last_sync,
               (SELECT COUNT(*) FROM sync_queue WHERE magaza_id = m.id AND status = 'pending') as pending_queue,
               (SELECT COUNT(*) FROM conflict_log WHERE magaza_id = m.id AND resolution_type = 'pending') as pending_conflicts,
               (SELECT COUNT(*) FROM offline_sales WHERE magaza_id = m.id AND status = 'pending') as offline_sales
        FROM magazalar m
        LEFT JOIN store_config sc ON m.id = sc.magaza_id AND sc.config_key = 'operation_mode'
        LEFT JOIN sync_metadata sm ON m.id = sm.magaza_id
        LEFT JOIN sync_stats ss ON m.id = ss.magaza_id AND ss.stat_date = CURDATE()
        ORDER BY m.id
    ");
    
    $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($stores as &$store) {
        // Operasyon modunu belirle
        $store['mode'] = $store['operation_mode'] ?? 'DIRECT'; // Varsayılan DIRECT
        
        // Mod bazlı durum belirleme
        if ($store['mode'] === 'DIRECT') {
            // Direct modda sürekli bağlı sayılır
            $store['status'] = 'online';
            $store['status_text'] = 'Direct Bağlantı';
            $store['minutes_since_sync'] = 0;
            $store['connection_type'] = 'Direct API';
        } else {
            // SYNC modda son sync'e göre durum belirle
            if ($store['last_sync']) {
                $last_sync_time = strtotime($store['last_sync']);
                $minutes_ago = (time() - $last_sync_time) / 60;
                
                if ($minutes_ago <= 5) {
                    $store['status'] = 'online';
                    $store['status_text'] = 'Senkronize';
                } elseif ($minutes_ago <= 30) {
                    $store['status'] = 'warning';
                    $store['status_text'] = 'Gecikmeli Sync';
                } else {
                    $store['status'] = 'offline';
                    $store['status_text'] = 'Sync Kesildi';
                }
                
                $store['minutes_since_sync'] = round($minutes_ago);
            } else {
                $store['status'] = 'never_synced';
                $store['status_text'] = 'İlk Sync Bekleniyor';
                $store['minutes_since_sync'] = null;
            }
            $store['connection_type'] = 'Webhook + Polling';
        }
        
        // URL'leri oluştur
        if ($store['mode'] === 'SYNC') {
            $store['webhook_url'] = generateWebhookUrl($store['id']);
            $store['sync_endpoint'] = generateSyncEndpoint($store['id']);
        } else {
            $store['webhook_url'] = null;
            $store['sync_endpoint'] = null;
        }
        
        // Hibrit mimari için ek bilgiler
        $store['supports_offline'] = ($store['mode'] === 'SYNC');
        $store['has_local_db'] = ($store['mode'] === 'SYNC');
        $store['real_time_sync'] = true; // Her iki modda da real-time
    }
    
    return $stores;
}

/**
 * Hibrit mimari durumu
 */
function getHybridArchitectureStatus() {
    global $conn;
    
    // Mod dağılımı
    $stmt = $conn->query("
        SELECT 
            COALESCE(sc.config_value, 'DIRECT') as mode,
            COUNT(*) as store_count
        FROM magazalar m
        LEFT JOIN store_config sc ON m.id = sc.magaza_id AND sc.config_key = 'operation_mode'
        GROUP BY COALESCE(sc.config_value, 'DIRECT')
    ");
    $mode_distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Sync frekansları
    $sync_frequencies = [
        'real_time' => [
            'operations' => ['Satış yapıldı', 'Stok değişti', 'Yeni müşteri', 'Puan değişimi', 'Borç ödeme'],
            'frequency' => 'Anında'
        ],
        'urgent' => [
            'operations' => ['Fiyat değişiklikleri', 'Yeni ürün', 'Müşteri güncelleme', 'Sistem ayarları'],
            'frequency' => '5-15 dakika'
        ],
        'daily' => [
            'operations' => ['Departman/Kategori', 'Tedarikci bilgileri', 'İndirim kampanyaları', 'Raporlama'],
            'frequency' => 'Gece 02:00'
        ]
    ];
    
    // Veri akış kuralları
    $data_flow_rules = [
        'STOK' => ['distribution' => 'Dağıtık', 'description' => 'Her mağazanın ayrı stoğu'],
        'MÜŞTERİ' => ['distribution' => 'Merkezi', 'description' => 'Tüm mağazalar görebilir/kullanabilir'],
        'PUAN' => ['distribution' => 'Merkezi', 'description' => 'Her mağazada kullanılabilir'],
        'BORÇ' => ['distribution' => 'Merkezi', 'description' => 'Tüm mağazalar görebilir/yönetebilir'],
        'FATURALAR' => ['distribution' => 'Merkezi', 'description' => 'Tüm mağazalar görebilir'],
        'SİSTEM_AYARLARI' => ['distribution' => 'Merkezi', 'description' => 'Tüm sistem ayarları']
    ];
    
    // Network endpoints
    $network_endpoints = [
        'ana_sunucu' => 'https://pos.incikirtasiye.com/api/sync/',
        'merkez' => 'https://merkez.incikirtasiye.com/sync/',
        'dolunay_current' => 'Direct connection to Ana Sunucu',
        'dolunay_future' => 'https://dolunay.incikirtasiye.com/sync/ (1 ay sonra)'
    ];
    
    // Geçiş planı durumu
    $transition_status = getTransitionStatus();
    
    return [
        'success' => true,
        'message' => 'Hibrit mimari durumu alındı',
        'architecture' => [
            'version' => '2.1',
            'type' => 'Hybrid',
            'mode_distribution' => $mode_distribution,
            'sync_frequencies' => $sync_frequencies,
            'data_flow_rules' => $data_flow_rules,
            'network_endpoints' => $network_endpoints,
            'transition_status' => $transition_status
        ],
        'checked_at' => date('Y-m-d H:i:s')
    ];
}

/**
 * Mod geçiş işlemi
 */
function handleModeTransition($store_id, $new_mode) {
    global $conn;
    
    if (!$store_id || !$new_mode) {
        throw new Exception('Mağaza ID ve yeni mod gerekli');
    }
    
    if (!in_array($new_mode, ['DIRECT', 'SYNC'])) {
        throw new Exception('Geçersiz mod. DIRECT veya SYNC olmalı');
    }
    
    // Mağaza bilgilerini al
    $stmt = $conn->prepare("SELECT id, ad FROM magazalar WHERE id = ?");
    $stmt->execute([$store_id]);
    $store = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$store) {
        throw new Exception('Mağaza bulunamadı');
    }
    
    // Mevcut modu al
    $stmt = $conn->prepare("
        SELECT config_value 
        FROM store_config 
        WHERE magaza_id = ? AND config_key = 'operation_mode'
    ");
    $stmt->execute([$store_id]);
    $current_mode = $stmt->fetchColumn() ?? 'DIRECT';
    
    if ($current_mode === $new_mode) {
        throw new Exception('Mağaza zaten ' . $new_mode . ' modunda');
    }
    
    try {
        $conn->beginTransaction();
        
        // Yeni modu kaydet
        $stmt = $conn->prepare("
            INSERT INTO store_config (magaza_id, config_key, config_value, updated_at)
            VALUES (?, 'operation_mode', ?, NOW())
            ON DUPLICATE KEY UPDATE 
            config_value = VALUES(config_value),
            updated_at = NOW()
        ");
        $stmt->execute([$store_id, $new_mode]);
        
        // Geçiş logunu kaydet
        $stmt = $conn->prepare("
            INSERT INTO sync_metadata (magaza_id, tablo_adi, last_error, son_sync_tarihi)
            VALUES (?, 'mode_transition', ?, NOW())
        ");
        $transition_log = "Mod değişikliği: {$current_mode} → {$new_mode}";
        $stmt->execute([$store_id, $transition_log]);
        
        // Mod özel işlemler
        if ($new_mode === 'SYNC') {
            // SYNC moduna geçiş: sync tablolarını hazırla
            $stmt = $conn->prepare("
                INSERT IGNORE INTO sync_stats (magaza_id, stat_date, total_operations, successful_operations, failed_operations)
                VALUES (?, CURDATE(), 0, 0, 0)
            ");
            $stmt->execute([$store_id]);
        } elseif ($new_mode === 'DIRECT') {
            // DIRECT moduna geçiş: offline satışları senkronize et
            $stmt = $conn->prepare("
                UPDATE offline_sales 
                SET status = 'migrated_to_direct'
                WHERE magaza_id = ? AND status = 'pending'
            ");
            $stmt->execute([$store_id]);
        }
        
        $conn->commit();
        
        return [
            'success' => true,
            'message' => "Mağaza {$store['ad']} başarıyla {$new_mode} moduna geçirildi",
            'store_id' => $store_id,
            'store_name' => $store['ad'],
            'previous_mode' => $current_mode,
            'new_mode' => $new_mode,
            'transition_time' => date('Y-m-d H:i:s')
        ];
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw new Exception('Mod geçiş hatası: ' . $e->getMessage());
    }
}

/**
 * Belirli mağaza durumu (hibrit mimari desteği ile)
 */
function getStoreStatus($store_id) {
    global $conn;
    
    if (!$store_id) {
        throw new Exception('Mağaza ID gerekli');
    }
    
    // Mağaza bilgileri ve modu
    $stmt = $conn->prepare("
        SELECT m.id, m.ad, 
               COALESCE(sc.config_value, 'DIRECT') as operation_mode,
               sc.updated_at as mode_last_updated
        FROM magazalar m
        LEFT JOIN store_config sc ON m.id = sc.magaza_id AND sc.config_key = 'operation_mode'
        WHERE m.id = ?
    ");
    $stmt->execute([$store_id]);
    $store = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$store) {
        throw new Exception('Mağaza bulunamadı');
    }
    
    $mode = $store['operation_mode'];
    
    // Sync metadata (sadece SYNC modunda)
    $sync_metadata = [];
    if ($mode === 'SYNC') {
        $stmt = $conn->prepare("
            SELECT * FROM sync_metadata 
            WHERE magaza_id = ? 
            ORDER BY son_sync_tarihi DESC
        ");
        $stmt->execute([$store_id]);
        $sync_metadata = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Sync istatistikleri (son 7 gün)
    $stmt = $conn->prepare("
        SELECT * FROM sync_stats 
        WHERE magaza_id = ? 
        AND stat_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ORDER BY stat_date DESC
    ");
    $stmt->execute([$store_id]);
    $sync_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Queue durumu (SYNC modu için)
    $queue_summary = [];
    if ($mode === 'SYNC') {
        $stmt = $conn->prepare("
            SELECT operation_type, COUNT(*) as count,
                   MIN(created_at) as oldest,
                   MAX(created_at) as newest
            FROM sync_queue 
            WHERE magaza_id = ? AND status = 'pending'
            GROUP BY operation_type
        ");
        $stmt->execute([$store_id]);
        $queue_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Offline satışlar (SYNC modu için)
    $offline_sales = [];
    if ($mode === 'SYNC') {
        $stmt = $conn->prepare("
            SELECT COUNT(*) as pending_count,
                   MIN(created_at) as oldest_sale,
                   MAX(created_at) as newest_sale
            FROM offline_sales 
            WHERE magaza_id = ? AND status = 'pending'
        ");
        $stmt->execute([$store_id]);
        $offline_sales = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Çakışmalar
    $stmt = $conn->prepare("
        SELECT conflict_type, COUNT(*) as count,
               MAX(created_at) as latest
        FROM conflict_log 
        WHERE magaza_id = ? AND resolution_type = 'pending'
        GROUP BY conflict_type
    ");
    $stmt->execute([$store_id]);
    $conflicts_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Mode-specific URLs
    $urls = [];
    if ($mode === 'SYNC') {
        $urls = [
            'webhook_url' => generateWebhookUrl($store_id),
            'sync_endpoint' => generateSyncEndpoint($store_id),
            'local_api_base' => generateLocalApiBase($store_id)
        ];
    }
    
    return [
        'success' => true,
        'message' => 'Mağaza durumu alındı',
        'store' => $store,
        'operation_mode' => $mode,
        'sync_metadata' => $sync_metadata,
        'sync_stats' => $sync_stats,
        'queue_summary' => $queue_summary,
        'offline_sales' => $offline_sales,
        'conflicts_summary' => $conflicts_summary,
        'urls' => $urls,
        'capabilities' => [
            'real_time_sync' => true,
            'offline_support' => ($mode === 'SYNC'),
            'local_database' => ($mode === 'SYNC'),
            'webhook_enabled' => ($mode === 'SYNC')
        ]
    ];
}

/**
 * Sync istatistikleri (hibrit mimari)
 */
function getSyncStatistics($period = 'today') {
    global $conn;
    
    // Tarih aralığını belirle
    switch ($period) {
        case 'today':
            $date_condition = "stat_date = CURDATE()";
            break;
        case 'week':
            $date_condition = "stat_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
            break;
        case 'month':
            $date_condition = "stat_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
            break;
        default:
            $date_condition = "stat_date = CURDATE()";
    }
    
    // Genel istatistikler (mod bazlı)
    $stmt = $conn->query("
        SELECT 
            COALESCE(sc.config_value, 'DIRECT') as mode,
            SUM(ss.total_operations) as total_operations,
            SUM(ss.successful_operations) as successful_operations,
            SUM(ss.failed_operations) as failed_operations,
            AVG(ss.avg_sync_time) as avg_sync_time,
            SUM(ss.data_volume_mb) as total_data_mb,
            COUNT(DISTINCT ss.magaza_id) as active_stores
        FROM sync_stats ss
        JOIN magazalar m ON ss.magaza_id = m.id
        LEFT JOIN store_config sc ON m.id = sc.magaza_id AND sc.config_key = 'operation_mode'
        WHERE {$date_condition}
        GROUP BY COALESCE(sc.config_value, 'DIRECT')
    ");
    $mode_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Genel toplam
    $overall_stats = [
        'total_operations' => array_sum(array_column($mode_stats, 'total_operations')),
        'successful_operations' => array_sum(array_column($mode_stats, 'successful_operations')),
        'failed_operations' => array_sum(array_column($mode_stats, 'failed_operations')),
        'avg_sync_time' => array_sum(array_column($mode_stats, 'avg_sync_time')) / count($mode_stats),
        'total_data_mb' => array_sum(array_column($mode_stats, 'total_data_mb')),
        'active_stores' => array_sum(array_column($mode_stats, 'active_stores'))
    ];
    
    // Başarı oranı hesapla
    $success_rate = 0;
    if ($overall_stats['total_operations'] > 0) {
        $success_rate = ($overall_stats['successful_operations'] / $overall_stats['total_operations']) * 100;
    }
    
    // Real-time işlemler (son 1 saat)
    $stmt = $conn->query("
        SELECT COUNT(*) as realtime_operations
        FROM sync_queue 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        AND operation_type IN ('sale', 'stock_update', 'customer_update')
    ");
    $realtime_ops = $stmt->fetchColumn() ?? 0;
    
    return [
        'success' => true,
        'message' => 'Hibrit mimari istatistikleri alındı',
        'period' => $period,
        'overall_stats' => array_merge($overall_stats, ['success_rate' => round($success_rate, 2)]),
        'mode_stats' => $mode_stats,
        'realtime_operations' => $realtime_ops,
        'generated_at' => date('Y-m-d H:i:s')
    ];
}

/**
 * Hibrit mimari health check
 */
function performHealthCheck() {
    global $conn;
    
    $health_status = 'healthy';
    $checks = [];
    $issues = [];
    
    // 1. Veritabanı bağlantısı
    try {
        $stmt = $conn->query("SELECT 1");
        $checks['database'] = ['status' => 'ok', 'message' => 'Ana sunucu veritabanı sağlıklı'];
    } catch (Exception $e) {
        $checks['database'] = ['status' => 'error', 'message' => 'Veritabanı bağlantı hatası: ' . $e->getMessage()];
        $health_status = 'critical';
        $issues[] = 'Ana sunucu veritabanı sorunu';
    }
    
    // 2. Hibrit mimari durumu
    try {
        $stmt = $conn->query("
            SELECT 
                COUNT(*) as total_stores,
                SUM(CASE WHEN COALESCE(sc.config_value, 'DIRECT') = 'SYNC' THEN 1 ELSE 0 END) as sync_stores,
                SUM(CASE WHEN COALESCE(sc.config_value, 'DIRECT') = 'DIRECT' THEN 1 ELSE 0 END) as direct_stores
            FROM magazalar m
            LEFT JOIN store_config sc ON m.id = sc.magaza_id AND sc.config_key = 'operation_mode'
        ");
        $mode_distribution = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $checks['hybrid_architecture'] = [
            'status' => 'ok', 
            'message' => "Hibrit mimari aktif: {$mode_distribution['sync_stores']} SYNC, {$mode_distribution['direct_stores']} DIRECT mağaza"
        ];
    } catch (Exception $e) {
        $checks['hybrid_architecture'] = ['status' => 'error', 'message' => 'Hibrit mimari durumu kontrol edilemedi'];
        $health_status = 'critical';
        $issues[] = 'Hibrit mimari sorunu';
    }
    
    // 3. SYNC modu sağlığı
    try {
        $stmt = $conn->query("
            SELECT COUNT(*) as pending_sync_operations
            FROM sync_queue sq
            JOIN store_config sc ON sq.magaza_id = sc.magaza_id
            WHERE sq.status = 'pending' 
            AND sc.config_key = 'operation_mode' 
            AND sc.config_value = 'SYNC'
        ");
        $pending_sync = $stmt->fetchColumn();
        
        if ($pending_sync > 500) {
            $checks['sync_mode'] = ['status' => 'warning', 'message' => "SYNC modunda yüksek queue: {$pending_sync} bekleyen işlem"];
            if ($health_status === 'healthy') $health_status = 'warning';
            $issues[] = 'SYNC modu yüksek yük';
        } else {
            $checks['sync_mode'] = ['status' => 'ok', 'message' => "SYNC modu sağlıklı: {$pending_sync} bekleyen işlem"];
        }
    } catch (Exception $e) {
        $checks['sync_mode'] = ['status' => 'error', 'message' => 'SYNC modu kontrol hatası'];
        $health_status = 'critical';
        $issues[] = 'SYNC modu sistemi sorunu';
    }
    
    // 4. DIRECT modu sağlığı
    try {
        $stmt = $conn->query("
            SELECT COUNT(*) as direct_stores
            FROM magazalar m
            JOIN store_config sc ON m.id = sc.magaza_id
            WHERE sc.config_key = 'operation_mode' 
            AND sc.config_value = 'DIRECT'
        ");
        $direct_stores = $stmt->fetchColumn();
        
        $checks['direct_mode'] = ['status' => 'ok', 'message' => "DIRECT modu aktif: {$direct_stores} mağaza"];
    } catch (Exception $e) {
        $checks['direct_mode'] = ['status' => 'error', 'message' => 'DIRECT modu kontrol hatası'];
        $health_status = 'critical';
        $issues[] = 'DIRECT modu sistemi sorunu';
    }
    
    // 5. Real-time sync performansı
    try {
        $stmt = $conn->query("
            SELECT COUNT(*) as recent_realtime
            FROM sync_queue 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            AND operation_type IN ('sale', 'stock_update', 'customer_update')
        ");
        $realtime_ops = $stmt->fetchColumn();
        
        if ($realtime_ops > 100) {
            $checks['realtime_sync'] = ['status' => 'warning', 'message' => "Yoğun real-time aktivite: {$realtime_ops} işlem/5dk"];
            if ($health_status === 'healthy') $health_status = 'warning';
        } else {
            $checks['realtime_sync'] = ['status' => 'ok', 'message' => "Real-time sync normal: {$realtime_ops} işlem/5dk"];
        }
    } catch (Exception $e) {
        $checks['realtime_sync'] = ['status' => 'error', 'message' => 'Real-time sync kontrol hatası'];
        $health_status = 'critical';
        $issues[] = 'Real-time sync sorunu';
    }
    
    // 6. Offline satışlar (SYNC modunda)
    try {
        $stmt = $conn->query("
            SELECT COUNT(*) as pending_offline_sales
            FROM offline_sales 
            WHERE status = 'pending'
        ");
        $offline_pending = $stmt->fetchColumn();
        
        if ($offline_pending > 50) {
            $checks['offline_sales'] = ['status' => 'warning', 'message' => "Bekleyen offline satış: {$offline_pending}"];
            if ($health_status === 'healthy') $health_status = 'warning';
            $issues[] = 'Offline satış birikimi';
        } else {
            $checks['offline_sales'] = ['status' => 'ok', 'message' => "Offline satış durumu normal: {$offline_pending} bekleyen"];
        }
    } catch (Exception $e) {
        $checks['offline_sales'] = ['status' => 'error', 'message' => 'Offline satış kontrol hatası'];
        $health_status = 'critical';
        $issues[] = 'Offline satış sistemi sorunu';
    }
    
    // 7. Webhook endpoints durumu
    try {
        $webhook_stores = [];
        $stmt = $conn->query("
            SELECT m.id, m.ad
            FROM magazalar m
            JOIN store_config sc ON m.id = sc.magaza_id
            WHERE sc.config_key = 'operation_mode' 
            AND sc.config_value = 'SYNC'
        ");
        $sync_stores = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($sync_stores as $store) {
            $webhook_url = generateWebhookUrl($store['id']);
            $webhook_stores[] = [
                'store_id' => $store['id'],
                'store_name' => $store['ad'],
                'webhook_url' => $webhook_url,
                'status' => 'configured' // Gerçek ping kontrolü eklenebilir
            ];
        }
        
        $checks['webhook_endpoints'] = [
            'status' => 'ok', 
            'message' => count($webhook_stores) . ' webhook endpoint yapılandırıldı',
            'details' => $webhook_stores
        ];
    } catch (Exception $e) {
        $checks['webhook_endpoints'] = ['status' => 'error', 'message' => 'Webhook endpoint kontrol hatası'];
        $health_status = 'critical';
        $issues[] = 'Webhook sistemi sorunu';
    }
    
    // 8. Disk alanı ve sistem kaynakları
    $disk_free = disk_free_space('.');
    $disk_total = disk_total_space('.');
    $disk_usage_percent = (($disk_total - $disk_free) / $disk_total) * 100;
    
    if ($disk_usage_percent > 90) {
        $checks['system_resources'] = ['status' => 'critical', 'message' => sprintf('Disk kullanımı kritik: %.1f%%', $disk_usage_percent)];
        $health_status = 'critical';
        $issues[] = 'Disk alanı kritik seviyede';
    } elseif ($disk_usage_percent > 80) {
        $checks['system_resources'] = ['status' => 'warning', 'message' => sprintf('Disk kullanımı yüksek: %.1f%%', $disk_usage_percent)];
        if ($health_status === 'healthy') $health_status = 'warning';
        $issues[] = 'Disk alanı azalıyor';
    } else {
        $checks['system_resources'] = ['status' => 'ok', 'message' => sprintf('Sistem kaynakları normal: %.1f%% disk kullanımı', $disk_usage_percent)];
    }
    
    return [
        'success' => true,
        'message' => 'Hibrit mimari health check tamamlandı',
        'overall_status' => $health_status,
        'architecture_version' => '2.1',
        'checks' => $checks,
        'issues' => $issues,
        'recommendations' => generateHealthRecommendations($health_status, $issues),
        'checked_at' => date('Y-m-d H:i:s')
    ];
}

/**
 * Queue durumu (hibrit mimari)
 */
function getQueueStatus($store_id = null) {
    global $conn;
    
    $where_clause = $store_id ? 'WHERE sq.magaza_id = ?' : '';
    $params = $store_id ? [$store_id] : [];
    
    // Mode bilgisi ile queue istatistikleri
    $stmt = $conn->prepare("
        SELECT sq.status, sq.operation_type, 
               COALESCE(sc.config_value, 'DIRECT') as store_mode,
               COUNT(*) as count,
               MIN(sq.created_at) as oldest,
               MAX(sq.created_at) as newest
        FROM sync_queue sq
        JOIN magazalar m ON sq.magaza_id = m.id
        LEFT JOIN store_config sc ON m.id = sc.magaza_id AND sc.config_key = 'operation_mode'
        {$where_clause}
        GROUP BY sq.status, sq.operation_type, COALESCE(sc.config_value, 'DIRECT')
        ORDER BY sq.status, sq.operation_type
    ");
    $stmt->execute($params);
    $queue_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Real-time işlemler (son 1 saat)
    $stmt = $conn->prepare("
        SELECT DATE_FORMAT(sq.created_at, '%Y-%m-%d %H:00:00') as hour,
               sq.operation_type, sq.status, COUNT(*) as count
        FROM sync_queue sq
        WHERE sq.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        {($store_id ? 'AND sq.magaza_id = ?' : '')}
        GROUP BY hour, sq.operation_type, sq.status
        ORDER BY hour DESC
    ");
    $stmt->execute($params);
    $hourly_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Mod bazlı failed operations
    $stmt = $conn->prepare("
        SELECT sq.id, sq.operation_type, sq.table_name, sq.record_id, 
               sq.attempts, sq.max_attempts, sq.error_message, sq.created_at,
               m.ad as store_name, COALESCE(sc.config_value, 'DIRECT') as store_mode
        FROM sync_queue sq
        JOIN magazalar m ON sq.magaza_id = m.id
        LEFT JOIN store_config sc ON m.id = sc.magaza_id AND sc.config_key = 'operation_mode'
        WHERE sq.status = 'failed' AND sq.attempts < sq.max_attempts
        {($store_id ? 'AND sq.magaza_id = ?' : '')}
        ORDER BY sq.created_at DESC
        LIMIT 20
    ");
    $stmt->execute($params);
    $failed_operations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'success' => true,
        'message' => 'Hibrit queue durumu alındı',
        'store_id' => $store_id,
        'queue_stats' => $queue_stats,
        'hourly_activity' => $hourly_activity,
        'failed_operations' => $failed_operations,
        'architecture_version' => '2.1',
        'checked_at' => date('Y-m-d H:i:s')
    ];
}

/**
 * Son aktiviteler (hibrit mimari)
 */
function getRecentActivity($limit = 20) {
    global $conn;
    
    // Son sync işlemleri (SYNC modundaki mağazalar)
    $stmt = $conn->prepare("
        SELECT 'sync' as activity_type, sm.magaza_id, m.ad as store_name,
               COALESCE(sc.config_value, 'DIRECT') as store_mode,
               sm.tablo_adi as details, sm.son_sync_tarihi as activity_time,
               sm.sync_durumu as status, sm.operation_count
        FROM sync_metadata sm
        JOIN magazalar m ON sm.magaza_id = m.id
        LEFT JOIN store_config sc ON m.id = sc.magaza_id AND sc.config_key = 'operation_mode'
        WHERE sm.son_sync_tarihi IS NOT NULL
        ORDER BY sm.son_sync_tarihi DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Son mode geçişleri
    $stmt = $conn->prepare("
        SELECT 'mode_transition' as activity_type, sc.magaza_id, m.ad as store_name,
               sc.config_value as store_mode,
               CONCAT('Mode changed to ', sc.config_value) as details, 
               sc.updated_at as activity_time,
               'completed' as status, 1 as operation_count
        FROM store_config sc
        JOIN magazalar m ON sc.magaza_id = m.id
        WHERE sc.config_key = 'operation_mode' 
        AND sc.updated_at IS NOT NULL
        ORDER BY sc.updated_at DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    $mode_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Real-time operations (son 1 saat)
    $stmt = $conn->prepare("
        SELECT 'realtime_operation' as activity_type, sq.magaza_id, m.ad as store_name,
               COALESCE(sc.config_value, 'DIRECT') as store_mode,
               sq.operation_type as details, sq.created_at as activity_time,
               sq.status, 1 as operation_count
        FROM sync_queue sq
        JOIN magazalar m ON sq.magaza_id = m.id
        LEFT JOIN store_config sc ON m.id = sc.magaza_id AND sc.config_key = 'operation_mode'
        WHERE sq.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        AND sq.operation_type IN ('sale', 'stock_update', 'customer_update')
        ORDER BY sq.created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    $realtime_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Tüm aktiviteleri birleştir ve sırala
    $all_activities = array_merge($activities, $mode_activities, $realtime_activities);
    usort($all_activities, function($a, $b) {
        return strtotime($b['activity_time']) - strtotime($a['activity_time']);
    });
    
    // Limiti uygula
    $all_activities = array_slice($all_activities, 0, $limit);
    
    return [
        'success' => true,
        'message' => 'Hibrit mimari son aktiviteler alındı',
        'activities' => $all_activities,
        'limit' => $limit,
        'architecture_version' => '2.1',
        'checked_at' => date('Y-m-d H:i:s')
    ];
}

// ==================== YARDIMCI FONKSİYONLAR ====================

/**
 * Geçiş planı durumu
 */
function getTransitionStatus() {
    global $conn;
    
    // Dolunay mağazasının durumunu kontrol et (ID: 2)
    $stmt = $conn->prepare("
        SELECT COALESCE(config_value, 'DIRECT') as current_mode,
               updated_at as last_mode_change
        FROM store_config 
        WHERE magaza_id = 2 AND config_key = 'operation_mode'
    ");
    $stmt->execute();
    $dolunay_status = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $transition_phases = [
        'phase_1_merkez' => [
            'name' => 'Merkez Full Sync',
            'status' => 'completed', // Merkez zaten SYNC modunda varsayalım
            'description' => 'Merkez mağaza SYNC moduna geçirildi',
            'target_date' => '2025-05-31'
        ],
        'phase_2_dolunay_direct' => [
            'name' => 'Dolunay Direct Mode',
            'status' => ($dolunay_status && $dolunay_status['current_mode'] === 'DIRECT') ? 'active' : 'planned',
            'description' => 'Dolunay mağaza DIRECT modda çalışıyor',
            'target_date' => '2025-05-31'
        ],
        'phase_3_dolunay_sync' => [
            'name' => 'Dolunay Sync Geçişi',
            'status' => ($dolunay_status && $dolunay_status['current_mode'] === 'SYNC') ? 'completed' : 'planned',
            'description' => 'Dolunay mağaza SYNC moduna geçiş (1 ay sonra)',
            'target_date' => '2025-07-01'
        ]
    ];
    
    return [
        'current_phase' => 'phase_2_dolunay_direct',
        'phases' => $transition_phases,
        'dolunay_current_mode' => $dolunay_status['current_mode'] ?? 'DIRECT',
        'estimated_completion' => '2025-07-01'
    ];
}

/**
 * Health check önerileri
 */
function generateHealthRecommendations($status, $issues) {
    $recommendations = [];
    
    foreach ($issues as $issue) {
        switch ($issue) {
            case 'SYNC modu yüksek yük':
                $recommendations[] = 'SYNC modundaki mağazaların sync frekansını azaltmayı düşünün';
                $recommendations[] = 'Queue worker sayısını artırarak işlem kapasitesini yükseltin';
                break;
                
            case 'Offline satış birikimi':
                $recommendations[] = 'Offline satışların senkronizasyon sürecini hızlandırın';
                $recommendations[] = 'SYNC modundaki mağazaların bağlantı durumunu kontrol edin';
                break;
                
            case 'Disk alanı kritik seviyede':
                $recommendations[] = 'Eski log dosyalarını temizleyin';
                $recommendations[] = 'Sync_stats tablosundaki eski kayıtları arşivleyin';
                break;
                
            case 'Yüksek çakışma oranı':
                $recommendations[] = 'Çakışma çözüm algoritmalarını gözden geçirin';
                $recommendations[] = 'Real-time sync frekansını ayarlayın';
                break;
                
            default:
                $recommendations[] = 'Sistem loglarını kontrol edin: ' . $issue;
        }
    }
    
    if ($status === 'healthy') {
        $recommendations[] = 'Sistem sağlıklı çalışıyor, rutin bakımları unutmayın';
    }
    
    return array_unique($recommendations);
}

/**
 * Webhook URL'si oluştur (hibrit mimari)
 */
function generateWebhookUrl($store_id) {
    $subdomain = '';
    switch ($store_id) {
        case 1:
            $subdomain = 'merkez';
            break;
        case 2:
            $subdomain = 'dolunay';
            break;
        default:
            $subdomain = 'magaza' . $store_id;
    }
    
    return "https://{$subdomain}.incikirtasiye.com/sync/receive_webhook.php";
}

/**
 * Sync endpoint URL'si oluştur
 */
function generateSyncEndpoint($store_id) {
    $subdomain = '';
    switch ($store_id) {
        case 1:
            $subdomain = 'merkez';
            break;
        case 2:
            $subdomain = 'dolunay';
            break;
        default:
            $subdomain = 'magaza' . $store_id;
    }
    
    return "https://{$subdomain}.incikirtasiye.com/sync/send_sales.php";
}

/**
 * Local API base URL'si oluştur
 */
function generateLocalApiBase($store_id) {
    $subdomain = '';
    switch ($store_id) {
        case 1:
            $subdomain = 'merkez';
            break;
        case 2:
            $subdomain = 'dolunay';
            break;
        default:
            $subdomain = 'magaza' . $store_id;
    }
    
    return "https://{$subdomain}.incikirtasiye.com/admin/api/local_sync/";
}

?>