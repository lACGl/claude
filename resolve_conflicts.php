<?php
/**
 * Ana Sunucu - Hibrit Çakışma Çözüm API'si v2.1
 * Merkez-Dolunay hibrit mimarisi için çakışma yönetimi
 * 
 * Desteklenen Çakışma Tipleri:
 * - stock_conflict: Stok rezervasyon ve negatif stok çakışmaları
 * - invoice_conflict: Fatura senkronizasyon çakışmaları
 * - data_conflict: Müşteri, puan, borç veri tutarsızlıkları
 * - sync_conflict: Senkronizasyon zaman damgası çakışmaları
 */

require_once '../../session_manager.php';
require_once '../../db_connection.php';
require_once '../../stock_functions.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-Store-ID, X-Sync-Token');

error_reporting(0);
ini_set('display_errors', 0);

$response = [
    'success' => false,
    'message' => '',
    'conflicts' => [],
    'resolutions' => [],
    'statistics' => null
];

try {
    // API Key ve Store ID validasyonu
    $api_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $store_id = $_SERVER['HTTP_X_STORE_ID'] ?? null;
    $sync_token = $_SERVER['HTTP_X_SYNC_TOKEN'] ?? null;
    
    if (!validateAPIAccess($api_key, $store_id)) {
        throw new Exception('Yetkisiz erişim - Geçersiz API key veya mağaza ID');
    }
    
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($method) {
        case 'GET':
            handleGetConflicts($store_id);
            break;
            
        case 'POST':
            handleCreateOrResolveConflict($store_id, $sync_token);
            break;
            
        case 'PUT':
            handleManualResolution($store_id);
            break;
            
        case 'DELETE':
            handleBulkCleanup($store_id);
            break;
            
        default:
            throw new Exception('Desteklenmeyen HTTP metodu: ' . $method);
    }

} catch (Exception $e) {
    error_log('Hibrit çakışma çözüm hatası: ' . $e->getMessage());
    $response['message'] = $e->getMessage();
    $response['error_code'] = 'CONFLICT_ERROR';
} catch (Throwable $t) {
    error_log('Kritik hibrit çakışma hatası: ' . $t->getMessage());
    $response['message'] = 'Sistem hatası: ' . $t->getMessage();
    $response['error_code'] = 'SYSTEM_ERROR';
}

echo json_encode($response);

// ==================== API VALIDATION ====================

/**
 * API erişimi doğrula
 */
function validateAPIAccess($api_key, $store_id) {
    global $conn;
    
    if (empty($api_key)) {
        return false;
    }
    
    // Development/test ortamı için basit kontrol
    if ($api_key === 'sync_api_key_2024') {
        return true;
    }
    
    // Production için database tabanlı API key kontrolü
    $stmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM sistem_ayarlari 
        WHERE anahtar = 'api_key' AND deger = ?
    ");
    $stmt->execute([$api_key]);
    
    return $stmt->fetchColumn() > 0;
}

// ==================== ÇAKIŞMA LİSTELEME ====================

/**
 * Hibrit mimaride çakışmaları listele
 */
function handleGetConflicts($store_id) {
    global $conn, $response;
    
    $conflict_type = $_GET['type'] ?? null;
    $status = $_GET['status'] ?? 'pending';
    $priority = $_GET['priority'] ?? null;
    $limit = min(100, (int)($_GET['limit'] ?? 50));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $include_stats = $_GET['include_stats'] ?? false;
    
    // Hibrit mimariye özel filtreler
    $sync_mode = $_GET['sync_mode'] ?? null; // 'direct', 'sync', 'both'
    $last_hours = (int)($_GET['last_hours'] ?? 24);
    
    // Temel sorgu koşulları
    $where_conditions = ['cl.created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)'];
    $params = [$last_hours];
    
    // Store ID filtresi - hibrit mimaride önemli
    if ($store_id) {
        $where_conditions[] = 'cl.magaza_id = ?';
        $params[] = $store_id;
    }
    
    if ($conflict_type) {
        $where_conditions[] = 'cl.conflict_type = ?';
        $params[] = $conflict_type;
    }
    
    if ($status) {
        $where_conditions[] = 'cl.resolution_type = ?';
        $params[] = $status;
    }
    
    // Hibrit mimariye özel priority filtresi
    if ($priority) {
        $where_conditions[] = 'JSON_EXTRACT(cl.conflict_data, "$.priority") = ?';
        $params[] = $priority;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Ana çakışma sorgusu - hibrit mimariye özel alanlar eklendi
    $stmt = $conn->prepare("
        SELECT cl.*,
               m.ad as magaza_adi,
               us.ad as urun_adi,
               us.barkod,
               CASE 
                   WHEN cl.resolved_at IS NOT NULL THEN 'resolved'
                   WHEN cl.resolution_type = 'pending' THEN 'pending'
                   WHEN cl.resolution_type = 'auto_resolved' THEN 'auto_resolved'
                   WHEN cl.resolution_type = 'manual_resolved' THEN 'manual_resolved'
                   ELSE 'processing'
               END as status,
               CASE 
                   WHEN cl.magaza_id = 1 THEN 'sync_mode'
                   WHEN cl.magaza_id = 2 THEN 'direct_mode'
                   ELSE 'unknown'
               END as store_mode,
               JSON_EXTRACT(cl.conflict_data, '$.priority') as priority_level,
               JSON_EXTRACT(cl.conflict_data, '$.sync_timestamp') as sync_timestamp,
               JSON_EXTRACT(cl.conflict_data, '$.resolution_urgency') as urgency
        FROM conflict_log cl
        LEFT JOIN magazalar m ON cl.magaza_id = m.id
        LEFT JOIN urun_stok us ON cl.urun_id = us.id
        WHERE {$where_clause}
        ORDER BY 
            CASE JSON_EXTRACT(cl.conflict_data, '$.priority')
                WHEN 'critical' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                WHEN 'low' THEN 4
                ELSE 5
            END,
            cl.created_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    
    $stmt->execute($params);
    $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Conflict data JSON'ları parse et ve hibrit bilgiler ekle
    foreach ($conflicts as &$conflict) {
        if ($conflict['conflict_data']) {
            $conflict_details = json_decode($conflict['conflict_data'], true);
            $conflict['conflict_details'] = $conflict_details;
            
            // Hibrit mimariye özel bilgiler
            $conflict['hybrid_info'] = [
                'store_mode' => $conflict['store_mode'],
                'sync_method' => $conflict_details['sync_method'] ?? 'unknown',
                'original_timestamp' => $conflict_details['original_timestamp'] ?? null,
                'detection_source' => $conflict_details['detection_source'] ?? 'auto'
            ];
        }
    }
    
    // Toplam sayı
    $count_stmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM conflict_log cl
        WHERE {$where_clause}
    ");
    $count_stmt->execute($params);
    $total_count = $count_stmt->fetchColumn();
    
    // İstatistikler (opsiyonel)
    $statistics = null;
    if ($include_stats) {
        $statistics = getConflictStatistics($store_id, $last_hours);
    }
    
    $response['success'] = true;
    $response['message'] = count($conflicts) . ' çakışma listelendi';
    $response['conflicts'] = $conflicts;
    $response['statistics'] = $statistics;
    $response['pagination'] = [
        'total' => $total_count,
        'limit' => $limit,
        'offset' => $offset,
        'has_more' => ($offset + $limit) < $total_count
    ];
}

/**
 * Hibrit mimaride çakışma istatistikleri
 */
function getConflictStatistics($store_id = null, $hours = 24) {
    global $conn;
    
    $where_clause = 'WHERE cl.created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)';
    $params = [$hours];
    
    if ($store_id) {
        $where_clause .= ' AND cl.magaza_id = ?';
        $params[] = $store_id;
    }
    
    $stmt = $conn->prepare("
        SELECT 
            cl.conflict_type,
            cl.resolution_type,
            COUNT(*) as count,
            CASE 
                WHEN cl.magaza_id = 1 THEN 'merkez_sync'
                WHEN cl.magaza_id = 2 THEN 'dolunay_direct'
                ELSE 'other'
            END as store_mode,
            AVG(TIMESTAMPDIFF(MINUTE, cl.created_at, cl.resolved_at)) as avg_resolution_time_minutes
        FROM conflict_log cl
        {$where_clause}
        GROUP BY cl.conflict_type, cl.resolution_type, store_mode
        ORDER BY count DESC
    ");
    
    $stmt->execute($params);
    $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Özet istatistikler
    $summary = [
        'total_conflicts' => array_sum(array_column($stats, 'count')),
        'by_type' => [],
        'by_status' => [],
        'by_store_mode' => [],
        'avg_resolution_time' => 0
    ];
    
    foreach ($stats as $stat) {
        $summary['by_type'][$stat['conflict_type']] = ($summary['by_type'][$stat['conflict_type']] ?? 0) + $stat['count'];
        $summary['by_status'][$stat['resolution_type']] = ($summary['by_status'][$stat['resolution_type']] ?? 0) + $stat['count'];
        $summary['by_store_mode'][$stat['store_mode']] = ($summary['by_store_mode'][$stat['store_mode']] ?? 0) + $stat['count'];
    }
    
    return [
        'summary' => $summary,
        'detailed' => $stats,
        'period_hours' => $hours,
        'generated_at' => date('Y-m-d H:i:s')
    ];
}

// ==================== ÇAKIŞMA OLUŞTURMA/ÇÖZME ====================

/**
 * Hibrit mimaride çakışma oluştur veya çöz
 */
function handleCreateOrResolveConflict($store_id, $sync_token) {
    global $conn, $response;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Geçersiz JSON formatı');
    }
    
    $action = $input['action'] ?? null;
    
    // Hibrit mimariye özel action'lar
    switch ($action) {
        case 'detect_conflicts':
            $response = detectHybridConflicts($input['data'], $store_id);
            break;
            
        case 'auto_resolve':
            $response = autoResolveConflicts($input['conflict_ids'], $store_id);
            break;
            
        case 'create_conflict':
            $response = createHybridConflict($input['conflict_data'], $store_id, $sync_token);
            break;
            
        case 'bulk_resolve':
            $response = bulkResolveConflicts($input['resolutions'], $store_id);
            break;
            
        case 'sync_validate':
            $response = validateSyncIntegrity($input['validation_data'], $store_id);
            break;
            
        case 'real_time_check':
            $response = performRealTimeConflictCheck($input['check_data'], $store_id);
            break;
            
        default:
            throw new Exception('Desteklenmeyen action: ' . $action);
    }
}

/**
 * Hibrit mimaride çakışma tespit et
 */
function detectHybridConflicts($data, $store_id) {
    global $conn;
    
    $detected_conflicts = [];
    
    // Hibrit mimariye özel çakışma tipleri
    $check_types = $data['check_types'] ?? ['stock', 'customers', 'prices', 'invoices', 'sync'];
    
    foreach ($check_types as $type) {
        switch ($type) {
            case 'stock':
                $stock_conflicts = detectHybridStockConflicts($store_id, $data);
                $detected_conflicts = array_merge($detected_conflicts, $stock_conflicts);
                break;
                
            case 'customers':
                $customer_conflicts = detectHybridCustomerConflicts($store_id, $data);
                $detected_conflicts = array_merge($detected_conflicts, $customer_conflicts);
                break;
                
            case 'prices':
                $price_conflicts = detectHybridPriceConflicts($store_id, $data);
                $detected_conflicts = array_merge($detected_conflicts, $price_conflicts);
                break;
                
            case 'invoices':
                $invoice_conflicts = detectHybridInvoiceConflicts($store_id, $data);
                $detected_conflicts = array_merge($detected_conflicts, $invoice_conflicts);
                break;
                
            case 'sync':
                $sync_conflicts = detectSyncConflicts($store_id, $data);
                $detected_conflicts = array_merge($detected_conflicts, $sync_conflicts);
                break;
        }
    }
    
    // Tespit edilen çakışmaları kaydet
    $saved_count = 0;
    foreach ($detected_conflicts as $conflict) {
        if (saveHybridConflict($conflict, $store_id)) {
            $saved_count++;
        }
    }
    
    return [
        'success' => true,
        'message' => "{$saved_count} hibrit çakışma tespit edildi ve kaydedildi",
        'conflicts' => $detected_conflicts,
        'store_mode' => $store_id == 1 ? 'sync_mode' : 'direct_mode'
    ];
}

/**
 * Hibrit stok çakışmalarını tespit et
 */
function detectHybridStockConflicts($store_id, $data) {
    global $conn;
    
    $conflicts = [];
    
    // 1. Negatif stoklar (her iki modda da kritik)
    $stmt = $conn->prepare("
        SELECT ms.magaza_id, ms.barkod, ms.stok_miktari,
               us.id as urun_id, us.ad as urun_adi,
               m.ad as magaza_adi,
               ms.son_guncelleme
        FROM magaza_stok ms
        JOIN urun_stok us ON ms.barkod = us.barkod
        JOIN magazalar m ON ms.magaza_id = m.id
        WHERE ms.stok_miktari < 0
        " . ($store_id ? "AND ms.magaza_id = ?" : "")
    );
    
    $params = $store_id ? [$store_id] : [];
    $stmt->execute($params);
    $negative_stocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($negative_stocks as $stock) {
        $conflicts[] = [
            'type' => 'stock_conflict',
            'magaza_id' => $stock['magaza_id'],
            'urun_id' => $stock['urun_id'],
            'priority' => 'critical', // Hibrit mimaride öncelik
            'data' => [
                'conflict_type' => 'negative_stock',
                'current_stock' => $stock['stok_miktari'],
                'product_name' => $stock['urun_adi'],
                'store_name' => $stock['magaza_adi'],
                'last_update' => $stock['son_guncelleme'],
                'detection_time' => date('Y-m-d H:i:s'),
                'store_mode' => $stock['magaza_id'] == 1 ? 'sync_mode' : 'direct_mode',
                'sync_method' => $stock['magaza_id'] == 1 ? 'webhook' : 'direct_api',
                'resolution_urgency' => 'immediate'
            ]
        ];
    }
    
    // 2. Rezervasyon çakışmaları (sadece aktif rezervasyonlar)
    $stmt = $conn->prepare("
        SELECT sr.*, us.ad as urun_adi, m.ad as magaza_adi,
               ms.stok_miktari as current_stock
        FROM stock_reservations sr
        JOIN urun_stok us ON sr.urun_id = us.id
        JOIN magazalar m ON sr.magaza_id = m.id
        LEFT JOIN magaza_stok ms ON sr.magaza_id = ms.magaza_id AND us.barkod = ms.barkod
        WHERE sr.status = 'active' 
        AND (sr.expires_at < NOW() OR ms.stok_miktari < sr.reserved_amount)
        " . ($store_id ? "AND sr.magaza_id = ?" : "")
    );
    
    if ($store_id) {
        $stmt->execute([$store_id]);
    } else {
        $stmt->execute();
    }
    
    $reservation_conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($reservation_conflicts as $reservation) {
        $is_expired = strtotime($reservation['expires_at']) < time();
        $insufficient_stock = $reservation['current_stock'] < $reservation['reserved_amount'];
        
        $conflicts[] = [
            'type' => 'stock_conflict',
            'magaza_id' => $reservation['magaza_id'],
            'urun_id' => $reservation['urun_id'],
            'priority' => $is_expired ? 'medium' : 'high',
            'data' => [
                'conflict_type' => $is_expired ? 'expired_reservation' : 'insufficient_reserved_stock',
                'reservation_id' => $reservation['id'],
                'reserved_amount' => $reservation['reserved_amount'],
                'current_stock' => $reservation['current_stock'],
                'expires_at' => $reservation['expires_at'],
                'product_name' => $reservation['urun_adi'],
                'store_name' => $reservation['magaza_adi'],
                'session_id' => $reservation['session_id'],
                'store_mode' => $reservation['magaza_id'] == 1 ? 'sync_mode' : 'direct_mode'
            ]
        ];
    }
    
    return $conflicts;
}

/**
 * Hibrit müşteri çakışmalarını tespit et
 */
function detectHybridCustomerConflicts($store_id, $data) {
    global $conn;
    
    $conflicts = [];
    
    // 1. Puan bakiyesi tutarsızlıkları (merkezi veri)
    $stmt = $conn->prepare("
        SELECT mp.musteri_id, mp.puan_bakiye,
               (SELECT SUM(kazanilan_puan) FROM puan_kazanma WHERE musteri_id = mp.musteri_id) as toplam_kazanilan,
               (SELECT SUM(harcanan_puan) FROM puan_harcama WHERE musteri_id = mp.musteri_id) as toplam_harcanan,
               m.ad, m.soyad, m.last_updated
        FROM musteri_puanlar mp
        JOIN musteriler m ON mp.musteri_id = m.id
        HAVING ABS(mp.puan_bakiye - (IFNULL(toplam_kazanilan, 0) - IFNULL(toplam_harcanan, 0))) > 0.01
    ");
    
    $stmt->execute();
    $point_conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($point_conflicts as $conflict) {
        $calculated_balance = ($conflict['toplam_kazanilan'] ?? 0) - ($conflict['toplam_harcanan'] ?? 0);
        
        $conflicts[] = [
            'type' => 'data_conflict',
            'magaza_id' => null, // Merkezi veri - tüm mağazaları etkiler
            'urun_id' => null,
            'priority' => 'medium',
            'data' => [
                'conflict_type' => 'customer_points_mismatch',
                'customer_id' => $conflict['musteri_id'],
                'customer_name' => $conflict['ad'] . ' ' . $conflict['soyad'],
                'current_balance' => $conflict['puan_bakiye'],
                'calculated_balance' => $calculated_balance,
                'difference' => $conflict['puan_bakiye'] - $calculated_balance,
                'last_customer_update' => $conflict['last_updated'],
                'data_type' => 'centralized', // Hibrit mimaride merkezi veri
                'affects_all_stores' => true
            ]
        ];
    }
    
    // 2. Borç tutarsızlıkları (yeni hibrit özellik)
    $stmt = $conn->prepare("
        SELECT mb.musteri_id, mb.borc_id, mb.toplam_tutar, mb.indirim_tutari,
               IFNULL(SUM(mbo.odeme_tutari), 0) as toplam_odeme,
               (mb.toplam_tutar - mb.indirim_tutari - IFNULL(SUM(mbo.odeme_tutari), 0)) as kalan_borc,
               m.ad, m.soyad, mg.ad as magaza_adi
        FROM musteri_borclar mb
        JOIN musteriler m ON mb.musteri_id = m.id
        LEFT JOIN musteri_borc_odemeler mbo ON mb.borc_id = mbo.borc_id
        LEFT JOIN magazalar mg ON mb.magaza_id = mg.id
        GROUP BY mb.borc_id
        HAVING kalan_borc < 0 OR (mb.odendi_mi = 1 AND kalan_borc > 0.01)
    ");
    
    $stmt->execute();
    $debt_conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($debt_conflicts as $debt) {
        $conflicts[] = [
            'type' => 'data_conflict',
            'magaza_id' => null, // Merkezi veri
            'urun_id' => null,
            'priority' => 'high',
            'data' => [
                'conflict_type' => 'customer_debt_mismatch',
                'customer_id' => $debt['musteri_id'],
                'customer_name' => $debt['ad'] . ' ' . $debt['soyad'],
                'debt_id' => $debt['borc_id'],
                'total_debt' => $debt['toplam_tutar'],
                'discount' => $debt['indirim_tutari'],
                'total_payment' => $debt['toplam_odeme'],
                'remaining_debt' => $debt['kalan_borc'],
                'store_name' => $debt['magaza_adi'],
                'data_type' => 'centralized'
            ]
        ];
    }
    
    return $conflicts;
}

/**
 * Hibrit fatura çakışmalarını tespit et (yeni)
 */
function detectHybridInvoiceConflicts($store_id, $data) {
    global $conn;
    
    $conflicts = [];
    
    // 1. Offline satışlar (sadece Merkez mağaza için geçerli)
    if (!$store_id || $store_id == 1) {
        $stmt = $conn->prepare("
            SELECT os.*, m.ad as magaza_adi
            FROM offline_sales os
            JOIN magazalar m ON os.magaza_id = m.id
            WHERE os.status = 'failed' OR 
                  (os.status = 'pending' AND os.created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR))
            ORDER BY os.created_at DESC
            LIMIT 50
        ");
        
        $stmt->execute();
        $offline_issues = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($offline_issues as $issue) {
            $conflicts[] = [
                'type' => 'invoice_conflict',
                'magaza_id' => $issue['magaza_id'],
                'urun_id' => null,
                'priority' => $issue['status'] == 'failed' ? 'high' : 'medium',
                'data' => [
                    'conflict_type' => 'offline_sale_sync_failure',
                    'local_invoice_id' => $issue['local_invoice_id'],
                    'sale_amount' => json_decode($issue['sale_data'], true)['genelToplam'] ?? 0,
                    'created_at' => $issue['created_at'],
                    'error_message' => $issue['error_message'],
                    'sync_attempts' => $issue['status'] == 'failed' ? 'max_reached' : 'retrying',
                    'store_name' => $issue['magaza_adi'],
                    'store_mode' => 'sync_mode'
                ]
            ];
        }
    }
    
    // 2. Fatura sıra numarası çakışmaları
    $stmt = $conn->prepare("
        SELECT sf1.magaza, sf1.fatura_no, sf1.fatura_tarihi, COUNT(*) as duplicate_count
        FROM satis_faturalari sf1
        WHERE sf1.fatura_tarihi >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        " . ($store_id ? "AND sf1.magaza = ?" : "") . "
        GROUP BY sf1.magaza, sf1.fatura_no
        HAVING duplicate_count > 1
    ");
    
    if ($store_id) {
        $stmt->execute([$store_id]);
    } else {
        $stmt->execute();
    }
    
    $duplicate_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($duplicate_invoices as $dup) {
        $conflicts[] = [
            'type' => 'invoice_conflict',
            'magaza_id' => $dup['magaza'],
            'urun_id' => null,
            'priority' => 'high',
            'data' => [
                'conflict_type' => 'duplicate_invoice_number',
                'invoice_number' => $dup['fatura_no'],
                'duplicate_count' => $dup['duplicate_count'],
                'invoice_date' => $dup['fatura_tarihi'],
                'store_mode' => $dup['magaza'] == 1 ? 'sync_mode' : 'direct_mode'
            ]
        ];
    }
    
    return $conflicts;
}

/**
 * Senkronizasyon çakışmalarını tespit et (yeni)
 */
function detectSyncConflicts($store_id, $data) {
    global $conn;
    
    $conflicts = [];
    
    // 1. Sync queue'da uzun süre bekleyen işlemler
    $stmt = $conn->prepare("
        SELECT sq.*, m.ad as magaza_adi
        FROM sync_queue sq
        JOIN magazalar m ON sq.magaza_id = m.id
        WHERE sq.status = 'pending' 
        AND sq.created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        AND sq.attempts >= sq.max_attempts - 1
        " . ($store_id ? "AND sq.magaza_id = ?" : "") . "
        ORDER BY sq.created_at ASC
        LIMIT 20
    ");
    
    if ($store_id) {
        $stmt->execute([$store_id]);
    } else {
        $stmt->execute();
    }
    
    $stuck_syncs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($stuck_syncs as $sync) {
        $conflicts[] = [
            'type' => 'sync_conflict',
            'magaza_id' => $sync['magaza_id'],
            'urun_id' => null,
            'priority' => 'high',
            'data' => [
                'conflict_type' => 'sync_queue_stuck',
                'sync_id' => $sync['id'],
                'operation_type' => $sync['operation_type'],
                'attempts' => $sync['attempts'],
                'max_attempts' => $sync['max_attempts'],
                'error_message' => $sync['error_message'],
                'created_at' => $sync['created_at'],
                'store_name' => $sync['magaza_adi'],
                'data_size' => strlen($sync['data_json']),
                'store_mode' => $sync['magaza_id'] == 1 ? 'sync_mode' : 'direct_mode'
            ]
        ];
    }
    
    // 2. Metadata senkronizasyon tutarsızlıkları
    $stmt = $conn->prepare("
        SELECT sm.*, m.ad as magaza_adi
        FROM sync_metadata sm
        JOIN magazalar m ON sm.magaza_id = m.id
        WHERE sm.sync_durumu = 'hata' 
        OR sm.son_sync_tarihi < DATE_SUB(NOW(), INTERVAL 2 HOUR)
        " . ($store_id ? "AND sm.magaza_id = ?" : "")
    );
    
    if ($store_id) {
        $stmt->execute([$store_id]);
    } else {
        $stmt->execute();
    }
    
    $metadata_issues = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($metadata_issues as $meta) {
        $hours_since_sync = round((time() - strtotime($meta['son_sync_tarihi'])) / 3600, 1);
        
        $conflicts[] = [
            'type' => 'sync_conflict',
            'magaza_id' => $meta['magaza_id'],
            'urun_id' => null,
            'priority' => $hours_since_sync > 4 ? 'critical' : 'medium',
            'data' => [
                'conflict_type' => 'sync_metadata_outdated',
                'table_name' => $meta['tablo_adi'],
                'last_sync' => $meta['son_sync_tarihi'],
                'hours_since_sync' => $hours_since_sync,
                'last_error' => $meta['last_error'],
                'store_name' => $meta['magaza_adi'],
                'sync_version' => $meta['sync_version']
            ]
        ];
    }
    
    return $conflicts;
}

/**
 * Hibrit çakışma kaydet
 */
function saveHybridConflict($conflict, $store_id) {
    global $conn;
    
    try {
        // Aynı çakışmanın zaten kaydedilip kaydedilmediğini kontrol et
        $stmt = $conn->prepare("
            SELECT id FROM conflict_log 
            WHERE conflict_type = ? 
            AND magaza_id = ? 
            AND urun_id = ?
            AND JSON_EXTRACT(conflict_data, '$.conflict_type') = ?
            AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        
        $stmt->execute([
            $conflict['type'],
            $conflict['magaza_id'],
            $conflict['urun_id'],
            $conflict['data']['conflict_type']
        ]);
        
        if ($stmt->fetch()) {
            return false; // Çakışma zaten kayıtlı
        }
        
        // Hibrit mimaride ek bilgiler ekle
        $conflict['data']['detection_source'] = 'auto_hybrid_scan';
        $conflict['data']['api_version'] = '2.1';
        $conflict['data']['original_timestamp'] = date('c');
        
        // Yeni çakışmayı kaydet
        $stmt = $conn->prepare("
            INSERT INTO conflict_log (
                conflict_type, magaza_id, urun_id, conflict_data, 
                resolution_type, created_at
            ) VALUES (?, ?, ?, ?, 'pending', NOW())
        ");
        
        return $stmt->execute([
            $conflict['type'],
            $conflict['magaza_id'],
            $conflict['urun_id'],
            json_encode($conflict['data'])
        ]);
        
    } catch (Exception $e) {
        error_log('Hibrit çakışma kaydetme hatası: ' . $e->getMessage());
        return false;
    }
}

// ==================== OTOMATİK ÇÖZÜM FONKSİYONLARI ====================

/**
 * Hibrit mimaride otomatik çakışma çözümü
 */
function autoResolveConflicts($conflict_ids, $store_id) {
    global $conn;
    
    if (!is_array($conflict_ids)) {
        $conflict_ids = [$conflict_ids];
    }
    
    $resolved_count = 0;
    $resolutions = [];
    
    foreach ($conflict_ids as $conflict_id) {
        $stmt = $conn->prepare("SELECT * FROM conflict_log WHERE id = ?");
        $stmt->execute([$conflict_id]);
        $conflict = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$conflict) {
            $resolutions[] = [
                'conflict_id' => $conflict_id,
                'success' => false,
                'message' => 'Çakışma bulunamadı'
            ];
            continue;
        }
        
        $conflict_data = json_decode($conflict['conflict_data'], true);
        $resolution_result = null;
        
        // Hibrit mimariye özel çözüm stratejileri
        switch ($conflict['conflict_type']) {
            case 'stock_conflict':
                $resolution_result = autoResolveHybridStockConflict($conflict, $conflict_data, $store_id);
                break;
                
            case 'data_conflict':
                $resolution_result = autoResolveHybridDataConflict($conflict, $conflict_data, $store_id);
                break;
                
            case 'invoice_conflict':
                $resolution_result = autoResolveHybridInvoiceConflict($conflict, $conflict_data, $store_id);
                break;
                
            case 'sync_conflict':
                $resolution_result = autoResolveSyncConflict($conflict, $conflict_data, $store_id);
                break;
                
            default:
                $resolution_result = ['success' => false, 'message' => 'Hibrit otomatik çözüm desteklenmiyor: ' . $conflict['conflict_type']];
        }
        
        if ($resolution_result['success']) {
            // Çakışmayı çözüldü olarak işaretle
            $stmt = $conn->prepare("
                UPDATE conflict_log 
                SET resolution_type = 'auto_resolved',
                    resolved_at = NOW(),
                    resolved_by = NULL,
                    notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$resolution_result['message'], $conflict_id]);
            
            $resolved_count++;
        }
        
        $resolutions[] = [
            'conflict_id' => $conflict_id,
            'success' => $resolution_result['success'],
            'message' => $resolution_result['message'],
            'resolution_details' => $resolution_result['details'] ?? null
        ];
    }
    
    return [
        'success' => true,
        'message' => "{$resolved_count} hibrit çakışma otomatik olarak çözüldü",
        'resolutions' => $resolutions,
        'store_mode' => $store_id == 1 ? 'sync_mode' : 'direct_mode'
    ];
}

/**
 * Hibrit stok çakışmasını otomatik çöz
 */
function autoResolveHybridStockConflict($conflict, $conflict_data, $store_id) {
    global $conn;
    
    switch ($conflict_data['conflict_type']) {
        case 'negative_stock':
            try {
                $conn->beginTransaction();
                
                // Negatif stoğu 0'a ayarla
                $stmt = $conn->prepare("
                    UPDATE magaza_stok 
                    SET stok_miktari = 0, son_guncelleme = NOW()
                    WHERE magaza_id = ? AND barkod = (
                        SELECT barkod FROM urun_stok WHERE id = ?
                    )
                ");
                $stmt->execute([$conflict['magaza_id'], $conflict['urun_id']]);
                
                // Hibrit mimaride stok hareketi kaydet
                $movement_data = [
                    'urun_id' => $conflict['urun_id'],
                    'miktar' => abs($conflict_data['current_stock']),
                    'hareket_tipi' => 'giris',
                    'aciklama' => 'Hibrit negatif stok düzeltmesi - ' . $conflict_data['store_mode'],
                    'tarih' => date('Y-m-d H:i:s'),
                    'kullanici_id' => null,
                    'magaza_id' => $conflict['magaza_id']
                ];
                
                addStockMovement($movement_data, $conn);
                
                // Ana stok tablosunu güncelle
                updateProductTotalStock($conflict['urun_id'], $conn);
                
                $conn->commit();
                
                return [
                    'success' => true, 
                    'message' => 'Hibrit negatif stok düzeltildi (0 yapıldı)',
                    'details' => [
                        'corrected_amount' => abs($conflict_data['current_stock']),
                        'store_mode' => $conflict_data['store_mode']
                    ]
                ];
                
            } catch (Exception $e) {
                $conn->rollBack();
                return ['success' => false, 'message' => 'Stok düzeltme hatası: ' . $e->getMessage()];
            }
            
        case 'expired_reservation':
            // Süresi dolmuş rezervasyonu iptal et
            $stmt = $conn->prepare("
                UPDATE stock_reservations 
                SET status = 'expired', 
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$conflict_data['reservation_id']]);
            
            return [
                'success' => true, 
                'message' => 'Hibrit süresi dolmuş rezervasyon iptal edildi',
                'details' => ['reservation_id' => $conflict_data['reservation_id']]
            ];
            
        case 'insufficient_reserved_stock':
            // Yetersiz stok rezervasyonunu güncelle
            $stmt = $conn->prepare("
                UPDATE stock_reservations 
                SET reserved_amount = (
                    SELECT stok_miktari 
                    FROM magaza_stok 
                    WHERE magaza_id = ? AND barkod = (
                        SELECT barkod FROM urun_stok WHERE id = ?
                    )
                ),
                status = 'adjusted',
                updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $conflict['magaza_id'], 
                $conflict['urun_id'], 
                $conflict_data['reservation_id']
            ]);
            
            return [
                'success' => true, 
                'message' => 'Rezervasyon miktarı mevcut stokla eşitlendi',
                'details' => ['adjusted_to_stock' => $conflict_data['current_stock']]
            ];
            
        default:
            return ['success' => false, 'message' => 'Bilinmeyen hibrit stok çakışması: ' . $conflict_data['conflict_type']];
    }
}

/**
 * Hibrit veri çakışmasını otomatik çöz
 */
function autoResolveHybridDataConflict($conflict, $conflict_data, $store_id) {
    global $conn;
    
    switch ($conflict_data['conflict_type']) {
        case 'customer_points_mismatch':
            // Merkezi puan sisteminde bakiye düzeltmesi
            $calculated_balance = $conflict_data['calculated_balance'];
            
            $stmt = $conn->prepare("
                UPDATE musteri_puanlar 
                SET puan_bakiye = ?,
                    last_updated = NOW()
                WHERE musteri_id = ?
            ");
            $stmt->execute([$calculated_balance, $conflict_data['customer_id']]);
            
            return [
                'success' => true, 
                'message' => "Hibrit merkezi puan bakiyesi düzeltildi: {$calculated_balance}",
                'details' => [
                    'old_balance' => $conflict_data['current_balance'],
                    'new_balance' => $calculated_balance,
                    'difference' => $conflict_data['difference']
                ]
            ];
            
        case 'customer_debt_mismatch':
            // Borç tutarsızlığını düzelt
            $debt_id = $conflict_data['debt_id'];
            $remaining = $conflict_data['remaining_debt'];
            
            if ($remaining <= 0) {
                // Borç tamamen ödenmişse, ödendi olarak işaretle
                $stmt = $conn->prepare("
                    UPDATE musteri_borclar 
                    SET odendi_mi = 1,
                        last_updated = NOW()
                    WHERE borc_id = ?
                ");
                $stmt->execute([$debt_id]);
                
                return [
                    'success' => true, 
                    'message' => 'Borç tamamen ödendi olarak işaretlendi',
                    'details' => ['debt_id' => $debt_id]
                ];
            } else {
                // Borç durumunu düzelt
                $stmt = $conn->prepare("
                    UPDATE musteri_borclar 
                    SET odendi_mi = 0,
                        last_updated = NOW()
                    WHERE borc_id = ?
                ");
                $stmt->execute([$debt_id]);
                
                return [
                    'success' => true, 
                    'message' => 'Borç durumu ödenmedi olarak düzeltildi',
                    'details' => ['debt_id' => $debt_id, 'remaining' => $remaining]
                ];
            }
            
        case 'price_mismatch':
            // Hibrit mimaride fiyat senkronizasyonu
            $stmt = $conn->prepare("
                UPDATE magaza_stok ms
                JOIN urun_stok us ON ms.barkod = us.barkod
                SET ms.satis_fiyati = us.satis_fiyati, 
                    ms.son_guncelleme = NOW()
                WHERE ms.magaza_id = ? AND us.id = ?
            ");
            $stmt->execute([$conflict['magaza_id'], $conflict['urun_id']]);
            
            return [
                'success' => true, 
                'message' => 'Hibrit fiyat senkronizasyonu tamamlandı',
                'details' => [
                    'store_price' => $conflict_data['store_price'],
                    'master_price' => $conflict_data['master_price']
                ]
            ];
            
        default:
            return ['success' => false, 'message' => 'Bilinmeyen hibrit veri çakışması: ' . $conflict_data['conflict_type']];
    }
}

/**
 * Hibrit fatura çakışmasını otomatik çöz
 */
function autoResolveHybridInvoiceConflict($conflict, $conflict_data, $store_id) {
    global $conn;
    
    switch ($conflict_data['conflict_type']) {
        case 'offline_sale_sync_failure':
            // Offline satışı tekrar senkronizasyon kuyruğuna ekle
            $stmt = $conn->prepare("
                UPDATE offline_sales 
                SET status = 'pending',
                    error_message = 'Çakışma çözümü: Yeniden deneniyor',
                    updated_at = NOW()
                WHERE local_invoice_id = ? AND magaza_id = ?
            ");
            $stmt->execute([$conflict_data['local_invoice_id'], $conflict['magaza_id']]);
            
            return [
                'success' => true, 
                'message' => 'Offline satış yeniden senkronizasyon kuyruğuna eklendi',
                'details' => ['local_invoice_id' => $conflict_data['local_invoice_id']]
            ];
            
        case 'duplicate_invoice_number':
            // Çakışan fatura numaralarını yeniden numaralandır
            $stmt = $conn->prepare("
                SELECT id, fatura_no, fatura_tarihi 
                FROM satis_faturalari 
                WHERE magaza = ? AND fatura_no = ?
                ORDER BY fatura_tarihi ASC
            ");
            $stmt->execute([$conflict['magaza_id'], $conflict_data['invoice_number']]);
            $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $fixed_count = 0;
            foreach ($duplicates as $index => $invoice) {
                if ($index > 0) { // İlkini olduğu gibi bırak
                    $new_number = $conflict_data['invoice_number'] . '-' . ($index + 1);
                    
                    $stmt = $conn->prepare("
                        UPDATE satis_faturalari 
                        SET fatura_no = ?,
                            last_updated = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$new_number, $invoice['id']]);
                    $fixed_count++;
                }
            }
            
            return [
                'success' => true, 
                'message' => "{$fixed_count} çakışan fatura numarası düzeltildi",
                'details' => ['fixed_invoices' => $fixed_count]
            ];
            
        default:
            return ['success' => false, 'message' => 'Bilinmeyen hibrit fatura çakışması: ' . $conflict_data['conflict_type']];
    }
}

/**
 * Senkronizasyon çakışmasını otomatik çöz
 */
function autoResolveSyncConflict($conflict, $conflict_data, $store_id) {
    global $conn;
    
    switch ($conflict_data['conflict_type']) {
        case 'sync_queue_stuck':
            // Takılan sync queue kaydını yeniden dene veya iptal et
            $sync_id = $conflict_data['sync_id'];
            
            if ($conflict_data['attempts'] >= 3) {
                // Max deneme sayısına ulaştıysa iptal et
                $stmt = $conn->prepare("
                    UPDATE sync_queue 
                    SET status = 'cancelled',
                        error_message = 'Otomatik iptal: Max deneme sayısına ulaşıldı',
                        processed_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$sync_id]);
                
                return [
                    'success' => true, 
                    'message' => 'Takılan sync işlemi iptal edildi',
                    'details' => ['sync_id' => $sync_id, 'reason' => 'max_attempts_reached']
                ];
            } else {
                // Yeniden dene
                $stmt = $conn->prepare("
                    UPDATE sync_queue 
                    SET status = 'pending',
                        attempts = 0,
                        error_message = 'Çakışma çözümü: Yeniden deneniyor',
                        scheduled_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$sync_id]);
                
                return [
                    'success' => true, 
                    'message' => 'Sync işlemi yeniden kuyruğa eklendi',
                    'details' => ['sync_id' => $sync_id]
                ];
            }
            
        case 'sync_metadata_outdated':
            // Metadata'yı güncelle
            $stmt = $conn->prepare("
                UPDATE sync_metadata 
                SET son_sync_tarihi = NOW(),
                    sync_durumu = 'basarili',
                    last_error = 'Çakışma çözümü: Metadata yenilendi'
                WHERE magaza_id = ? AND tablo_adi = ?
            ");
            $stmt->execute([$conflict['magaza_id'], $conflict_data['table_name']]);
            
            return [
                'success' => true, 
                'message' => 'Sync metadata güncellendi',
                'details' => [
                    'table' => $conflict_data['table_name'],
                    'hours_behind' => $conflict_data['hours_since_sync']
                ]
            ];
            
        default:
            return ['success' => false, 'message' => 'Bilinmeyen sync çakışması: ' . $conflict_data['conflict_type']];
    }
}

// ==================== BULK İŞLEMLER ====================

/**
 * Toplu çakışma çözümü
 */
function bulkResolveConflicts($resolutions, $store_id) {
    global $conn;
    
    $success_count = 0;
    $results = [];
    
    $conn->beginTransaction();
    
    try {
        foreach ($resolutions as $resolution) {
            $conflict_id = $resolution['conflict_id'];
            $resolution_type = $resolution['resolution_type'];
            $resolution_data = $resolution['data'] ?? [];
            
            $result = resolveConflictManually($conflict_id, $resolution_type, $resolution_data);
            
            if ($result['success']) {
                $success_count++;
            }
            
            $results[] = [
                'conflict_id' => $conflict_id,
                'success' => $result['success'],
                'message' => $result['message']
            ];
        }
        
        $conn->commit();
        
        return [
            'success' => true,
            'message' => "{$success_count}/" . count($resolutions) . " çakışma toplu olarak çözüldü",
            'results' => $results
        ];
        
    } catch (Exception $e) {
        $conn->rollBack();
        return [
            'success' => false,
            'message' => 'Toplu çözüm hatası: ' . $e->getMessage(),
            'results' => $results
        ];
    }
}

/**
 * Toplu çakışma temizliği
 */
function handleBulkCleanup($store_id) {
    global $conn, $response;
    
    $input = json_decode(file_get_contents('php://input'), true);
    $cleanup_type = $input['cleanup_type'] ?? null;
    $older_than_days = (int)($input['older_than_days'] ?? 7);
    
    $cleaned_count = 0;
    
    switch ($cleanup_type) {
        case 'resolved':
            // Çözülmüş çakışmaları temizle
            $stmt = $conn->prepare("
                DELETE FROM conflict_log 
                WHERE resolution_type IN ('auto_resolved', 'manual_resolved', 'ignored')
                AND resolved_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                " . ($store_id ? "AND magaza_id = ?" : "")
            );
            
            if ($store_id) {
                $stmt->execute([$older_than_days, $store_id]);
            } else {
                $stmt->execute([$older_than_days]);
            }
            
            $cleaned_count = $stmt->rowCount();
            break;
            
        case 'all_old':
            // Tüm eski çakışmaları temizle
            $stmt = $conn->prepare("
                DELETE FROM conflict_log 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                " . ($store_id ? "AND magaza_id = ?" : "")
            );
            
            if ($store_id) {
                $stmt->execute([$older_than_days, $store_id]);
            } else {
                $stmt->execute([$older_than_days]);
            }
            
            $cleaned_count = $stmt->rowCount();
            break;
            
        default:
            throw new Exception('Desteklenmeyen temizlik tipi: ' . $cleanup_type);
    }
    
    $response['success'] = true;
    $response['message'] = "{$cleaned_count} çakışma kaydı temizlendi";
    $response['cleanup_details'] = [
        'type' => $cleanup_type,
        'older_than_days' => $older_than_days,
        'cleaned_count' => $cleaned_count
    ];
}

// ==================== MANUEL ÇÖZÜM ====================

/**
 * Manuel çakışma çözümü (hibrit mimaride güncellenmiş)
 */
function handleManualResolution($store_id) {
    global $conn, $response;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Geçersiz JSON formatı');
    }
    
    $conflict_id = $input['conflict_id'] ?? null;
    $resolution_type = $input['resolution_type'] ?? null;
    $resolution_data = $input['resolution_data'] ?? [];
    
    if (!$conflict_id || !$resolution_type) {
        throw new Exception('Çakışma ID ve çözüm tipi gerekli');
    }
    
    // Hibrit mimaride ek doğrulama
    if ($store_id && isset($resolution_data['target_store']) && $resolution_data['target_store'] != $store_id) {
        throw new Exception('Mağaza yetki hatası: Farklı mağaza çakışması çözülmeye çalışılıyor');
    }
    
    $result = resolveConflictManually($conflict_id, $resolution_type, $resolution_data);
    
    $response['success'] = $result['success'];
    $response['message'] = $result['message'];
    $response['resolution_details'] = $result['details'] ?? null;
    $response['store_mode'] = $store_id == 1 ? 'sync_mode' : 'direct_mode';
}

/**
 * Gerçek zamanlı çakışma kontrolü (yeni hibrit özellik)
 */
function performRealTimeConflictCheck($check_data, $store_id) {
    global $conn;
    
    $operation_type = $check_data['operation_type'] ?? null;
    $data_snapshot = $check_data['data'] ?? [];
    
    $conflicts = [];
    
    switch ($operation_type) {
        case 'before_sale':
            // Satış öncesi stok kontrolü
            foreach ($data_snapshot['items'] as $item) {
                $stmt = $conn->prepare("
                    SELECT stok_miktari 
                    FROM magaza_stok 
                    WHERE magaza_id = ? AND barkod = ?
                ");
                $stmt->execute([$store_id, $item['barkod']]);
                $current_stock = $stmt->fetchColumn();
                
                if ($current_stock < $item['quantity']) {
                    $conflicts[] = [
                        'type' => 'real_time_stock_conflict',
                        'item' => $item,
                        'current_stock' => $current_stock,
                        'required' => $item['quantity'],
                        'shortage' => $item['quantity'] - $current_stock
                    ];
                }
            }
            break;
            
        case 'before_sync':
            // Senkronizasyon öncesi çakışma kontrolü
            $conflicts = detectSyncConflicts($store_id, ['quick_check' => true]);
            break;
    }
    
    return [
        'success' => true,
        'message' => count($conflicts) . ' gerçek zamanlı çakışma tespit edildi',
        'conflicts' => $conflicts,
        'operation_safe' => count($conflicts) == 0
    ];
}

/**
 * Senkronizasyon bütünlüğü doğrulaması (yeni hibrit özellik)
 */
function validateSyncIntegrity($validation_data, $store_id) {
    global $conn;
    
    $validation_results = [];
    $overall_integrity = true;
    
    // 1. Stok bütünlüğü kontrolü
    $stmt = $conn->prepare("
        SELECT us.id, us.barkod, us.stok_miktari as master_stock,
               IFNULL(SUM(ms.stok_miktari), 0) as total_store_stock
        FROM urun_stok us
        LEFT JOIN magaza_stok ms ON us.barkod = ms.barkod
        " . ($store_id ? "WHERE ms.magaza_id = ? OR ms.magaza_id IS NULL" : "") . "
        GROUP BY us.id
        HAVING ABS(master_stock - total_store_stock) > 0.01
        LIMIT 10
    ");
    
    if ($store_id) {
        $stmt->execute([$store_id]);
    } else {
        $stmt->execute();
    }
    
    $stock_mismatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($stock_mismatches) > 0) {
        $overall_integrity = false;
        $validation_results['stock_integrity'] = [
            'status' => 'failed',
            'mismatches' => count($stock_mismatches),
            'sample_issues' => array_slice($stock_mismatches, 0, 5)
        ];
    } else {
        $validation_results['stock_integrity'] = ['status' => 'passed'];
    }
    
    // 2. Müşteri veri bütünlüğü
    $stmt = $conn->prepare("
        SELECT COUNT(*) as mismatch_count
        FROM musteri_puanlar mp
        JOIN musteriler m ON mp.musteri_id = m.id
        WHERE ABS(mp.puan_bakiye - (
            IFNULL((SELECT SUM(kazanilan_puan) FROM puan_kazanma WHERE musteri_id = mp.musteri_id), 0) -
            IFNULL((SELECT SUM(harcanan_puan) FROM puan_harcama WHERE musteri_id = mp.musteri_id), 0)
        )) > 0.01
    ");
    
    $stmt->execute();
    $customer_mismatches = $stmt->fetchColumn();
    
    if ($customer_mismatches > 0) {
        $overall_integrity = false;
        $validation_results['customer_integrity'] = [
            'status' => 'failed',
            'mismatches' => $customer_mismatches
        ];
    } else {
        $validation_results['customer_integrity'] = ['status' => 'passed'];
    }
    
    return [
        'success' => true,
        'message' => $overall_integrity ? 'Sync bütünlüğü doğrulandı' : 'Sync bütünlük sorunları tespit edildi',
        'overall_integrity' => $overall_integrity,
        'validation_results' => $validation_results,
        'store_mode' => $store_id == 1 ? 'sync_mode' : 'direct_mode'
    ];
}

/**
 * Manuel çakışma çözümü (hibrit mimaride güncellenmiş)
 */
function resolveConflictManually($conflict_id, $resolution_type, $resolution_data) {
    global $conn;
    
    // Çakışma detaylarını al
    $stmt = $conn->prepare("SELECT * FROM conflict_log WHERE id = ?");
    $stmt->execute([$conflict_id]);
    $conflict = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$conflict) {
        return ['success' => false, 'message' => 'Çakışma bulunamadı'];
    }
    
    $conflict_data = json_decode($conflict['conflict_data'], true);
    
    $conn->beginTransaction();
    
    try {
        $result = null;
        
        // Hibrit mimaride çözüm türleri
        switch ($resolution_type) {
            case 'accept_server':
                $result = acceptServerVersionHybrid($conflict, $conflict_data, $resolution_data);
                break;
                
            case 'accept_store':
                $result = acceptStoreVersionHybrid($conflict, $conflict_data, $resolution_data);
                break;
                
            case 'merge_data':
                $result = mergeConflictingDataHybrid($conflict, $conflict_data, $resolution_data);
                break;
                
            case 'custom_fix':
                $result = applyCustomFixHybrid($conflict, $conflict_data, $resolution_data);
                break;
                
            case 'ignore':
                $result = ['success' => true, 'message' => 'Hibrit çakışma görmezden gelindi'];
                break;
                
            case 'escalate':
                $result = escalateConflict($conflict, $conflict_data, $resolution_data);
                break;
                
            default:
                throw new Exception('Desteklenmeyen hibrit çözüm tipi: ' . $resolution_type);
        }
        
        if ($result['success']) {
            // Çakışmayı çözüldü olarak işaretle
            $stmt = $conn->prepare("
                UPDATE conflict_log 
                SET resolution_type = 'manual_resolved',
                    resolved_at = NOW(),
                    resolved_by = ?,
                    notes = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $_SESSION['user_id'] ?? null,
                $resolution_data['notes'] ?? $result['message'],
                $conflict_id
            ]);
            
            $conn->commit();
            
            return [
                'success' => true, 
                'message' => $result['message'], 
                'details' => $result['details'] ?? null
            ];
        } else {
            $conn->rollBack();
            return $result;
        }
        
    } catch (Exception $e) {
        $conn->rollBack();
        return ['success' => false, 'message' => 'Manuel çözüm hatası: ' . $e->getMessage()];
    }
}

/**
 * Sunucu versiyonunu kabul et (hibrit mimarisi)
 */
function acceptServerVersionHybrid($conflict, $conflict_data, $resolution_data) {
    global $conn;
    
    $conflict_type = $conflict_data['conflict_type'];
    
    switch ($conflict_type) {
        case 'price_mismatch':
            // Ana sunucudaki fiyatı mağazaya uygula
            $stmt = $conn->prepare("
                UPDATE magaza_stok ms
                JOIN urun_stok us ON ms.barkod = us.barkod
                SET ms.satis_fiyati = us.satis_fiyati,
                    ms.son_guncelleme = NOW()
                WHERE ms.magaza_id = ? AND us.id = ?
            ");
            $stmt->execute([$conflict['magaza_id'], $conflict['urun_id']]);
            
            return [
                'success' => true, 
                'message' => 'Ana sunucu fiyatı mağazaya uygulandı',
                'details' => [
                    'old_price' => $conflict_data['store_price'],
                    'new_price' => $conflict_data['master_price']
                ]
            ];
            
        case 'customer_points_mismatch':
            // Ana sunucudaki puan bakiyesi geçerli kabul edildi
            return [
                'success' => true, 
                'message' => 'Ana sunucu puan bakiyesi kabul edildi (değişiklik yok)',
                'details' => ['current_balance' => $conflict_data['current_balance']]
            ];
            
        default:
            return ['success' => false, 'message' => 'Bu çakışma tipi için sunucu versiyonu kabul edilemez'];
    }
}

/**
 * Mağaza versiyonunu kabul et (hibrit mimarisi)
 */
function acceptStoreVersionHybrid($conflict, $conflict_data, $resolution_data) {
    global $conn;
    
    $conflict_type = $conflict_data['conflict_type'];
    
    switch ($conflict_type) {
        case 'price_mismatch':
            // Mağaza fiyatını ana sunucuya uygula
            $stmt = $conn->prepare("
                UPDATE urun_stok 
                SET satis_fiyati = ?,
                    last_updated = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$conflict_data['store_price'], $conflict['urun_id']]);
            
            // Fiyat geçmişine ekle
            addPriceHistory([
                'urun_id' => $conflict['urun_id'],
                'islem_tipi' => 'satis_fiyati_guncelleme',
                'eski_fiyat' => $conflict_data['master_price'],
                'yeni_fiyat' => $conflict_data['store_price'],
                'aciklama' => 'Hibrit çakışma çözümü - mağaza fiyatı kabul edildi',
                'kullanici_id' => $_SESSION['user_id'] ?? null
            ], $conn);
            
            return [
                'success' => true, 
                'message' => 'Mağaza fiyatı ana sunucuya uygulandı',
                'details' => [
                    'old_price' => $conflict_data['master_price'],
                    'new_price' => $conflict_data['store_price']
                ]
            ];
            
        case 'customer_points_mismatch':
            // Hesaplanan bakiyeyi mağaza lehine ayarla (nadiren kullanılır)
            $adjusted_balance = $resolution_data['custom_balance'] ?? $conflict_data['current_balance'];
            
            $stmt = $conn->prepare("
                UPDATE musteri_puanlar 
                SET puan_bakiye = ?
                WHERE musteri_id = ?
            ");
            $stmt->execute([$adjusted_balance, $conflict_data['customer_id']]);
            
            return [
                'success' => true, 
                'message' => 'Mağaza önerisi doğrultusunda puan bakiyesi ayarlandı',
                'details' => ['new_balance' => $adjusted_balance]
            ];
            
        default:
            return ['success' => false, 'message' => 'Bu çakışma tipi için mağaza versiyonu kabul edilemez'];
    }
}

/**
 * Çakışan verileri birleştir (yeni hibrit özellik)
 */
function mergeConflictingDataHybrid($conflict, $conflict_data, $resolution_data) {
    global $conn;
    
    $conflict_type = $conflict_data['conflict_type'];
    
    switch ($conflict_type) {
        case 'customer_points_mismatch':
            // Özel bir birleştirme formülü uygula
            $merge_strategy = $resolution_data['merge_strategy'] ?? 'average';
            
            $current = $conflict_data['current_balance'];
            $calculated = $conflict_data['calculated_balance'];
            
            switch ($merge_strategy) {
                case 'average':
                    $merged_balance = ($current + $calculated) / 2;
                    break;
                case 'higher':
                    $merged_balance = max($current, $calculated);
                    break;
                case 'lower':
                    $merged_balance = min($current, $calculated);
                    break;
                default:
                    $merged_balance = $calculated; // Varsayılan olarak hesaplanan değer
            }
            
            $stmt = $conn->prepare("
                UPDATE musteri_puanlar 
                SET puan_bakiye = ?
                WHERE musteri_id = ?
            ");
            $stmt->execute([$merged_balance, $conflict_data['customer_id']]);
            
            return [
                'success' => true, 
                'message' => "Puan bakiyesi birleştirme stratejisi ile ayarlandı: {$merge_strategy}",
                'details' => [
                    'strategy' => $merge_strategy,
                    'old_balance' => $current,
                    'calculated_balance' => $calculated,
                    'merged_balance' => $merged_balance
                ]
            ];
            
        default:
            return ['success' => false, 'message' => 'Bu çakışma tipi için veri birleştirme desteklenmiyor'];
    }
}

/**
 * Özel hibrit düzeltme uygula
 */
function applyCustomFixHybrid($conflict, $conflict_data, $resolution_data) {
    global $conn;
    
    $custom_action = $resolution_data['custom_action'] ?? null;
    $parameters = $resolution_data['parameters'] ?? [];
    
    switch ($custom_action) {
        case 'reset_stock_to_zero':
            // Ürün stoğunu sıfırla ve hareket kaydı oluştur
            $stmt = $conn->prepare("
                UPDATE magaza_stok 
                SET stok_miktari = 0, son_guncelleme = NOW()
                WHERE magaza_id = ? AND barkod = (
                    SELECT barkod FROM urun_stok WHERE id = ?
                )
            ");
            $stmt->execute([$conflict['magaza_id'], $conflict['urun_id']]);
            
            // Stok hareketi kaydet
            addStockMovement([
                'urun_id' => $conflict['urun_id'],
                'miktar' => abs($conflict_data['current_stock'] ?? 0),
                'hareket_tipi' => 'cikis',
                'aciklama' => 'Özel hibrit düzeltme: Stok sıfırlandı - ' . ($parameters['reason'] ?? 'Manuel düzeltme'),
                'tarih' => date('Y-m-d H:i:s'),
                'kullanici_id' => $_SESSION['user_id'] ?? null,
                'magaza_id' => $conflict['magaza_id']
            ], $conn);
            
            return [
                'success' => true, 
                'message' => 'Özel düzeltme: Ürün stoğu sıfırlandı',
                'details' => ['reason' => $parameters['reason'] ?? 'Manuel düzeltme']
            ];
            
        case 'adjust_customer_points':
            // Müşteri puanını özel değerle ayarla
            $new_balance = $parameters['new_balance'] ?? 0;
            
            $stmt = $conn->prepare("
                UPDATE musteri_puanlar 
                SET puan_bakiye = ?
                WHERE musteri_id = ?
            ");
            $stmt->execute([$new_balance, $conflict_data['customer_id']]);
            
            return [
                'success' => true, 
                'message' => 'Özel düzeltme: Müşteri puan bakiyesi ayarlandı',
                'details' => ['new_balance' => $new_balance]
            ];
            
        case 'sync_prices_from_master':
            // Tüm mağaza fiyatlarını ana sunucudan senkronize et
            $stmt = $conn->prepare("
                UPDATE magaza_stok ms
                JOIN urun_stok us ON ms.barkod = us.barkod
                SET ms.satis_fiyati = us.satis_fiyati,
                    ms.son_guncelleme = NOW()
                WHERE ms.magaza_id = ?
            ");
            $stmt->execute([$conflict['magaza_id']]);
            
            $updated_count = $stmt->rowCount();
            
            return [
                'success' => true, 
                'message' => "Özel düzeltme: {$updated_count} ürün fiyatı senkronize edildi",
                'details' => ['updated_products' => $updated_count]
            ];
            
        default:
            return [
                'success' => false, 
                'message' => 'Bilinmeyen özel düzeltme aksiyonu: ' . $custom_action
            ];
    }
}

/**
 * Çakışmayı üst seviyeye escalate et
 */
function escalateConflict($conflict, $conflict_data, $resolution_data) {
    global $conn;
    
    $escalation_level = $resolution_data['escalation_level'] ?? 'admin';
    $escalation_reason = $resolution_data['reason'] ?? 'Manuel escalation';
    
    // Çakışmayı escalate edildi olarak işaretle
    $stmt = $conn->prepare("
        UPDATE conflict_log 
        SET resolution_type = 'escalated',
            notes = CONCAT(IFNULL(notes, ''), '\nEscalated: ', ?, ' - Level: ', ?),
            JSON_SET(conflict_data, '$.escalation_level', ?, '$.escalation_reason', ?, '$.escalated_at', NOW())
        WHERE id = ?
    ");
    
    $stmt->execute([
        $escalation_reason,
        $escalation_level,
        $escalation_level,
        $escalation_reason,
        $conflict['id']
    ]);
    
    // TODO: Burada email/SMS bildirimi gönderilebilir
    
    return [
        'success' => true,
        'message' => "Çakışma {$escalation_level} seviyesine escalate edildi",
        'details' => [
            'escalation_level' => $escalation_level,
            'reason' => $escalation_reason,
            'requires_admin_attention' => true
        ]
    ];
}

// ==================== YARDIMCI FONKSİYONLAR ====================

/**
 * Hibrit mimaride çakışma öncelik hesaplama
 */
function calculateConflictPriority($conflict_type, $conflict_data, $store_mode) {
    $base_priority = [
        'stock_conflict' => 4,
        'invoice_conflict' => 3,
        'data_conflict' => 2,
        'sync_conflict' => 1
    ];
    
    $priority_score = $base_priority[$conflict_type] ?? 1;
    
    // Store mode bazlı öncelik ayarlaması
    if ($store_mode === 'direct_mode') {
        $priority_score += 1; // Direct mode daha kritik
    }
    
    // Çakışma tipine göre özel kurallar
    switch ($conflict_data['conflict_type']) {
        case 'negative_stock':
            $priority_score += 2;
            break;
        case 'offline_sale_sync_failure':
            $priority_score += 1;
            break;
        case 'sync_queue_stuck':
            if ($conflict_data['attempts'] >= 3) {
                $priority_score += 2;
            }
            break;
    }
    
    // Öncelik seviyesine çevir
    if ($priority_score >= 6) return 'critical';
    if ($priority_score >= 4) return 'high';
    if ($priority_score >= 2) return 'medium';
    return 'low';
}

/**
 * Hibrit mimaride log kaydetme
 */
function logHybridConflictActivity($conflict_id, $activity, $details = null) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            INSERT INTO conflict_log_activity (
                conflict_id, activity_type, activity_details, created_at
            ) VALUES (?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $conflict_id,
            $activity,
            $details ? json_encode($details) : null
        ]);
    } catch (Exception $e) {
        error_log('Hibrit çakışma log hatası: ' . $e->getMessage());
    }
}

/**
 * Çakışma bildirim sistemi
 */
function sendConflictNotification($conflict, $resolution_result = null) {
    // TODO: Hibrit mimariye özel bildirim sistemi
    // Email, SMS, Slack webhook vb. entegrasyonlar
    
    $notification_data = [
        'conflict_id' => $conflict['id'],
        'store_mode' => $conflict['magaza_id'] == 1 ? 'sync_mode' : 'direct_mode',
        'conflict_type' => $conflict['conflict_type'],
        'resolution_status' => $resolution_result ? 'resolved' : 'detected',
        'timestamp' => date('c')
    ];
    
    // Log olarak kaydet (gerçek implementasyon için genişletilebilir)
    error_log('Hibrit çakışma bildirimi: ' . json_encode($notification_data));
}

?>