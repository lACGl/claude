<?php
/**
 * Ana Sunucu - Veri Dağıtım Koordinatörü v2.1
 * Hibrit Mimari için akıllı veri dağıtım ve senkronizasyon sistemi
 * 
 * Fonksiyonlar:
 * - Merkezi verilerin tüm mağazalara dağıtımı (MÜŞTERİ, PUAN, BORÇ, FATURALAR)
 * - Dağıtık verilerin koordinasyonu (STOK)
 * - SYNC ve DIRECT mod desteği
 * - Öncelik bazlı dağıtım
 * - Batch işlem optimizasyonu
 */

require_once '../../session_manager.php';
require_once '../../db_connection.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-Distribution-Priority');

error_reporting(0);
ini_set('display_errors', 0);

$response = [
    'success' => false,
    'message' => '',
    'distribution_results' => [],
    'queue_status' => [],
    'statistics' => []
];

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? $_POST['action'] ?? 'distribute';
    
    switch ($action) {
        case 'distribute':
            $response = performDataDistribution();
            break;
            
        case 'distribute_customer_data':
            $response = distributeCentralCustomerData();
            break;
            
        case 'distribute_invoice_data':
            $response = distributeCentralInvoiceData();
            break;
            
        case 'distribute_system_settings':
            $response = distributeSystemSettings();
            break;
            
        case 'coordinate_stock':
            $response = coordinateStockData();
            break;
            
        case 'batch_distribute':
            $response = performBatchDistribution();
            break;
            
        case 'queue_status':
            $response = getDistributionQueueStatus();
            break;
            
        case 'force_sync':
            $response = forceFullSynchronization();
            break;
            
        case 'distribution_statistics':
            $response = getDistributionStatistics();
            break;
            
        case 'test_endpoints':
            $response = testAllEndpoints();
            break;
            
        case 'priority_distribution':
            $response = performPriorityDistribution();
            break;
            
        case 'health_check':
            $response = performDistributionHealthCheck();
            break;
            
        default:
            throw new Exception('Desteklenmeyen action: ' . $action);
    }

} catch (Exception $e) {
    error_log('Data Distributor hatası: ' . $e->getMessage());
    $response['message'] = $e->getMessage();
} catch (Throwable $t) {
    error_log('Data Distributor kritik hata: ' . $t->getMessage());
    $response['message'] = 'Sistem hatası: ' . $t->getMessage();
}

echo json_encode($response);

// ==================== ANA DAĞITIM FONKSİYONLARI ====================

/**
 * Ana veri dağıtım koordinatörü
 */
function performDataDistribution() {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $data_type = $input['data_type'] ?? 'all';
    $target_stores = $input['target_stores'] ?? 'all';
    $priority = $input['priority'] ?? 'normal';
    $force_update = $input['force_update'] ?? false;
    
    $distribution_results = [];
    
    // Veri türüne göre dağıtım stratejisi belirle
    switch ($data_type) {
        case 'customer':
            $distribution_results['customer'] = distributeCentralCustomerData($target_stores, $priority);
            break;
            
        case 'invoice':
            $distribution_results['invoice'] = distributeCentralInvoiceData($target_stores, $priority);
            break;
            
        case 'points':
            $distribution_results['points'] = distributeCentralPointsData($target_stores, $priority);
            break;
            
        case 'credits':
            $distribution_results['credits'] = distributeCentralCreditsData($target_stores, $priority);
            break;
            
        case 'stock':
            $distribution_results['stock'] = coordinateStockData($target_stores, $priority);
            break;
            
        case 'system_settings':
            $distribution_results['system'] = distributeSystemSettings($target_stores, $priority);
            break;
            
        case 'all':
            // Tüm merkezi verileri dağıt
            $distribution_results['customer'] = distributeCentralCustomerData($target_stores, $priority);
            $distribution_results['invoice'] = distributeCentralInvoiceData($target_stores, $priority);
            $distribution_results['points'] = distributeCentralPointsData($target_stores, $priority);
            $distribution_results['credits'] = distributeCentralCreditsData($target_stores, $priority);
            $distribution_results['system'] = distributeSystemSettings($target_stores, $priority);
            break;
            
        default:
            throw new Exception('Desteklenmeyen veri türü: ' . $data_type);
    }
    
    // Dağıtım sonuçlarını özetle
    $summary = summarizeDistributionResults($distribution_results);
    
    return [
        'success' => true,
        'message' => 'Veri dağıtımı tamamlandı',
        'data_type' => $data_type,
        'target_stores' => $target_stores,
        'priority' => $priority,
        'distribution_results' => $distribution_results,
        'summary' => $summary,
        'completed_at' => date('Y-m-d H:i:s')
    ];
}

/**
 * Merkezi müşteri verilerini dağıt
 */
function distributeCentralCustomerData($target_stores = 'all', $priority = 'normal') {
    global $conn;
    
    try {
        // Son güncellenmiş müşteri verilerini al
        $last_sync = getLastSyncTime('customer_data');
        
        $stmt = $conn->prepare("
            SELECT m.*, 
                   mp.puan_bakiye, mp.puan_oran, mp.musteri_turu,
                   COALESCE(mb.odenmemis_borc, 0) as odenmemis_borc,
                   COALESCE(mb.toplam_borc, 0) as toplam_borc
            FROM musteriler m
            LEFT JOIN musteri_puanlar mp ON m.id = mp.musteri_id
            LEFT JOIN (
                SELECT musteri_id, 
                       SUM(CASE WHEN odendi_mi = 0 THEN (toplam_tutar - COALESCE(indirim_tutari, 0)) ELSE 0 END) as odenmemis_borc,
                       SUM(toplam_tutar - COALESCE(indirim_tutari, 0)) as toplam_borc
                FROM musteri_borclar 
                GROUP BY musteri_id
            ) mb ON m.id = mb.musteri_id
            WHERE m.last_updated > ?
            ORDER BY m.last_updated DESC
            LIMIT 500
        ");
        
        $stmt->execute([$last_sync]);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($customers)) {
            return [
                'success' => true,
                'message' => 'Güncellenecek müşteri verisi yok',
                'updated_count' => 0
            ];
        }
        
        // Hedef mağazaları belirle
        $stores = getTargetStores($target_stores);
        
        $distribution_results = [];
        
        foreach ($stores as $store) {
            $result = sendDataToStore($store, 'customer_update', [
                'customers' => $customers,
                'update_type' => 'central_sync',
                'priority' => $priority
            ]);
            
            $distribution_results[] = [
                'store_id' => $store['id'],
                'store_name' => $store['ad'],
                'success' => $result['success'],
                'customer_count' => count($customers),
                'message' => $result['message'] ?? 'Başarılı'
            ];
        }
        
        // Sync zamanını güncelle
        updateLastSyncTime('customer_data');
        
        return [
            'success' => true,
            'message' => 'Müşteri verileri dağıtıldı',
            'updated_customers' => count($customers),
            'target_stores' => count($stores),
            'distribution_results' => $distribution_results
        ];
        
    } catch (Exception $e) {
        throw new Exception('Müşteri veri dağıtımı hatası: ' . $e->getMessage());
    }
}

/**
 * Merkezi fatura verilerini dağıt
 */
function distributeCentralInvoiceData($target_stores = 'all', $priority = 'normal') {
    global $conn;
    
    try {
        // Son güncellenmiş fatura verilerini al
        $last_sync = getLastSyncTime('invoice_data');
        
        $stmt = $conn->prepare("
            SELECT sf.*,
                   m.ad as musteri_adi,
                   m.soyad as musteri_soyadi,
                   mag.ad as magaza_adi,
                   p.ad as personel_adi
            FROM satis_faturalari sf
            LEFT JOIN musteriler m ON sf.musteri_id = m.id
            LEFT JOIN magazalar mag ON sf.magaza = mag.id
            LEFT JOIN personel p ON sf.personel = p.id
            WHERE sf.last_updated > ?
            AND sf.sync_durumu >= 0
            ORDER BY sf.last_updated DESC
            LIMIT 200
        ");
        
        $stmt->execute([$last_sync]);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($invoices)) {
            return [
                'success' => true,
                'message' => 'Güncellenecek fatura verisi yok',
                'updated_count' => 0
            ];
        }
        
        // Her fatura için detayları da al
        foreach ($invoices as &$invoice) {
            $stmt = $conn->prepare("
                SELECT sfd.*, us.ad as urun_adi, us.barkod
                FROM satis_fatura_detay sfd
                LEFT JOIN urun_stok us ON sfd.urun_id = us.id
                WHERE sfd.fatura_id = ?
            ");
            $stmt->execute([$invoice['id']]);
            $invoice['details'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Hedef mağazaları belirle
        $stores = getTargetStores($target_stores);
        
        $distribution_results = [];
        
        foreach ($stores as $store) {
            $result = sendDataToStore($store, 'invoice_update', [
                'invoices' => $invoices,
                'update_type' => 'central_sync',
                'priority' => $priority
            ]);
            
            $distribution_results[] = [
                'store_id' => $store['id'],
                'store_name' => $store['ad'],
                'success' => $result['success'],
                'invoice_count' => count($invoices),
                'message' => $result['message'] ?? 'Başarılı'
            ];
        }
        
        // Sync zamanını güncelle
        updateLastSyncTime('invoice_data');
        
        return [
            'success' => true,
            'message' => 'Fatura verileri dağıtıldı',
            'updated_invoices' => count($invoices),
            'target_stores' => count($stores),
            'distribution_results' => $distribution_results
        ];
        
    } catch (Exception $e) {
        throw new Exception('Fatura veri dağıtımı hatası: ' . $e->getMessage());
    }
}

/**
 * Merkezi puan verilerini dağıt
 */
function distributeCentralPointsData($target_stores = 'all', $priority = 'normal') {
    global $conn;
    
    try {
        // Son güncellenmiş puan verilerini al
        $last_sync = getLastSyncTime('points_data');
        
        // Puan kazanma kayıtları
        $stmt = $conn->prepare("
            SELECT pk.*, m.ad, m.soyad, m.telefon
            FROM puan_kazanma pk
            LEFT JOIN musteriler m ON pk.musteri_id = m.id
            WHERE pk.tarih > ?
            ORDER BY pk.tarih DESC
            LIMIT 100
        ");
        $stmt->execute([$last_sync]);
        $point_earnings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Puan harcama kayıtları
        $stmt = $conn->prepare("
            SELECT ph.*, m.ad, m.soyad, m.telefon
            FROM puan_harcama ph
            LEFT JOIN musteriler m ON ph.musteri_id = m.id
            WHERE ph.tarih > ?
            ORDER BY ph.tarih DESC
            LIMIT 100
        ");
        $stmt->execute([$last_sync]);
        $point_spendings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Güncel puan bakiyeleri
        $stmt = $conn->prepare("
            SELECT mp.*, m.ad, m.soyad, m.telefon
            FROM musteri_puanlar mp
            LEFT JOIN musteriler m ON mp.musteri_id = m.id
            WHERE m.last_updated > ?
            ORDER BY m.last_updated DESC
            LIMIT 500
        ");
        $stmt->execute([$last_sync]);
        $point_balances = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $points_data = [
            'earnings' => $point_earnings,
            'spendings' => $point_spendings,
            'balances' => $point_balances
        ];
        
        // Hedef mağazaları belirle
        $stores = getTargetStores($target_stores);
        
        $distribution_results = [];
        
        foreach ($stores as $store) {
            $result = sendDataToStore($store, 'points_update', [
                'points_data' => $points_data,
                'update_type' => 'central_sync',
                'priority' => $priority
            ]);
            
            $distribution_results[] = [
                'store_id' => $store['id'],
                'store_name' => $store['ad'],
                'success' => $result['success'],
                'records_count' => count($point_earnings) + count($point_spendings) + count($point_balances),
                'message' => $result['message'] ?? 'Başarılı'
            ];
        }
        
        // Sync zamanını güncelle
        updateLastSyncTime('points_data');
        
        return [
            'success' => true,
            'message' => 'Puan verileri dağıtıldı',
            'earnings_count' => count($point_earnings),
            'spendings_count' => count($point_spendings),
            'balances_count' => count($point_balances),
            'target_stores' => count($stores),
            'distribution_results' => $distribution_results
        ];
        
    } catch (Exception $e) {
        throw new Exception('Puan veri dağıtımı hatası: ' . $e->getMessage());
    }
}

/**
 * Merkezi borç verilerini dağıt
 */
function distributeCentralCreditsData($target_stores = 'all', $priority = 'normal') {
    global $conn;
    
    try {
        // Son güncellenmiş borç verilerini al
        $last_sync = getLastSyncTime('credits_data');
        
        // Borç kayıtları
        $stmt = $conn->prepare("
            SELECT mb.*,
                   m.ad as musteri_adi,
                   m.soyad as musteri_soyadi,
                   m.telefon as musteri_telefon,
                   mag.ad as magaza_adi,
                   COALESCE(SUM(mbo.odeme_tutari), 0) as toplam_odeme,
                   ((mb.toplam_tutar - COALESCE(mb.indirim_tutari, 0)) - COALESCE(SUM(mbo.odeme_tutari), 0)) as kalan_borc
            FROM musteri_borclar mb
            LEFT JOIN musteriler m ON mb.musteri_id = m.id
            LEFT JOIN magazalar mag ON mb.magaza_id = mag.id
            LEFT JOIN musteri_borc_odemeler mbo ON mb.borc_id = mbo.borc_id
            WHERE mb.olusturma_tarihi > ?
            GROUP BY mb.borc_id
            ORDER BY mb.olusturma_tarihi DESC
            LIMIT 100
        ");
        $stmt->execute([$last_sync]);
        $credits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Borç ödemeleri
        $stmt = $conn->prepare("
            SELECT mbo.*, mb.musteri_id, m.ad, m.soyad
            FROM musteri_borc_odemeler mbo
            LEFT JOIN musteri_borclar mb ON mbo.borc_id = mb.borc_id
            LEFT JOIN musteriler m ON mb.musteri_id = m.id
            WHERE mbo.olusturma_tarihi > ?
            ORDER BY mbo.olusturma_tarihi DESC
            LIMIT 100
        ");
        $stmt->execute([$last_sync]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $credits_data = [
            'credits' => $credits,
            'payments' => $payments
        ];
        
        // Hedef mağazaları belirle
        $stores = getTargetStores($target_stores);
        
        $distribution_results = [];
        
        foreach ($stores as $store) {
            $result = sendDataToStore($store, 'credits_update', [
                'credits_data' => $credits_data,
                'update_type' => 'central_sync',
                'priority' => $priority
            ]);
            
            $distribution_results[] = [
                'store_id' => $store['id'],
                'store_name' => $store['ad'],
                'success' => $result['success'],
                'credits_count' => count($credits),
                'payments_count' => count($payments),
                'message' => $result['message'] ?? 'Başarılı'
            ];
        }
        
        // Sync zamanını güncelle
        updateLastSyncTime('credits_data');
        
        return [
            'success' => true,
            'message' => 'Borç verileri dağıtıldı',
            'credits_count' => count($credits),
            'payments_count' => count($payments),
            'target_stores' => count($stores),
            'distribution_results' => $distribution_results
        ];
        
    } catch (Exception $e) {
        throw new Exception('Borç veri dağıtımı hatası: ' . $e->getMessage());
    }
}

/**
 * Sistem ayarlarını dağıt
 */
function distributeSystemSettings($target_stores = 'all', $priority = 'normal') {
    global $conn;
    
    try {
        // Son güncellenmiş sistem ayarlarını al
        $last_sync = getLastSyncTime('system_settings');
        
        $stmt = $conn->prepare("
            SELECT * FROM sistem_ayarlari 
            WHERE guncelleme_tarihi > ?
            ORDER BY guncelleme_tarihi DESC
        ");
        $stmt->execute([$last_sync]);
        $system_settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Puan ayarları
        $stmt = $conn->prepare("
            SELECT * FROM puan_ayarlari 
            WHERE guncelleme_tarihi > ?
            ORDER BY guncelleme_tarihi DESC
        ");
        $stmt->execute([$last_sync]);
        $point_settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $settings_data = [
            'system_settings' => $system_settings,
            'point_settings' => $point_settings
        ];
        
        if (empty($system_settings) && empty($point_settings)) {
            return [
                'success' => true,
                'message' => 'Güncellenecek sistem ayarı yok',
                'updated_count' => 0
            ];
        }
        
        // Hedef mağazaları belirle
        $stores = getTargetStores($target_stores);
        
        $distribution_results = [];
        
        foreach ($stores as $store) {
            $result = sendDataToStore($store, 'system_settings_update', [
                'settings_data' => $settings_data,
                'update_type' => 'central_sync',
                'priority' => $priority
            ]);
            
            $distribution_results[] = [
                'store_id' => $store['id'],
                'store_name' => $store['ad'],
                'success' => $result['success'],
                'settings_count' => count($system_settings) + count($point_settings),
                'message' => $result['message'] ?? 'Başarılı'
            ];
        }
        
        // Sync zamanını güncelle
        updateLastSyncTime('system_settings');
        
        return [
            'success' => true,
            'message' => 'Sistem ayarları dağıtıldı',
            'system_settings_count' => count($system_settings),
            'point_settings_count' => count($point_settings),
            'target_stores' => count($stores),
            'distribution_results' => $distribution_results
        ];
        
    } catch (Exception $e) {
        throw new Exception('Sistem ayarları dağıtımı hatası: ' . $e->getMessage());
    }
}

/**
 * Stok verilerini koordine et (Dağıtık veri)
 */
function coordinateStockData($target_stores = 'all', $priority = 'normal') {
    global $conn;
    
    try {
        // Son güncellenmiş ürün ve fiyat verilerini al
        $last_sync = getLastSyncTime('stock_data');
        
        $stmt = $conn->prepare("
            SELECT us.*, 
                   d.ad as departman_adi,
                   ag.ad as ana_grup_adi,
                   alg.ad as alt_grup_adi,
                   b.ad as birim_adi
            FROM urun_stok us
            LEFT JOIN departmanlar d ON us.departman_id = d.id
            LEFT JOIN ana_gruplar ag ON us.ana_grup_id = ag.id
            LEFT JOIN alt_gruplar alg ON us.alt_grup_id = alg.id
            LEFT JOIN birimler b ON us.birim_id = b.id
            WHERE us.last_updated > ?
            ORDER BY us.last_updated DESC
            LIMIT 300
        ");
        
        $stmt->execute([$last_sync]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Aktif indirimleri al
        $stmt = $conn->prepare("
            SELECT i.*, 
                   GROUP_CONCAT(id.urun_id) as urun_ids
            FROM indirimler i
            LEFT JOIN indirim_detay id ON i.id = id.indirim_id
            WHERE i.olusturulma_tarihi > ? 
            AND i.durum = 'aktif'
            AND i.bitis_tarihi >= CURDATE()
            GROUP BY i.id
            ORDER BY i.olusturulma_tarihi DESC
        ");
        $stmt->execute([$last_sync]);
        $discounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stock_data = [
            'products' => $products,
            'discounts' => $discounts
        ];
        
        if (empty($products) && empty($discounts)) {
            return [
                'success' => true,
                'message' => 'Güncellenecek ürün verisi yok',
                'updated_count' => 0
            ];
        }
        
        // Hedef mağazaları belirle
        $stores = getTargetStores($target_stores);
        
        $distribution_results = [];
        
        foreach ($stores as $store) {
            $result = sendDataToStore($store, 'stock_update', [
                'stock_data' => $stock_data,
                'update_type' => 'coordination',
                'priority' => $priority
            ]);
            
            $distribution_results[] = [
                'store_id' => $store['id'],
                'store_name' => $store['ad'],
                'success' => $result['success'],
                'products_count' => count($products),
                'discounts_count' => count($discounts),
                'message' => $result['message'] ?? 'Başarılı'
            ];
        }
        
        // Sync zamanını güncelle
        updateLastSyncTime('stock_data');
        
        return [
            'success' => true,
            'message' => 'Stok verileri koordine edildi',
            'products_count' => count($products),
            'discounts_count' => count($discounts),
            'target_stores' => count($stores),
            'distribution_results' => $distribution_results
        ];
        
    } catch (Exception $e) {
        throw new Exception('Stok koordinasyonu hatası: ' . $e->getMessage());
    }
}

// ==================== BATCH VE ÖNCELİK FONKSİYONLARI ====================

/**
 * Batch veri dağıtımı
 */
function performBatchDistribution() {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $batch_operations = $input['batch_operations'] ?? [];
    $max_concurrent = $input['max_concurrent'] ?? 5;
    
    if (empty($batch_operations)) {
        throw new Exception('Batch operasyonları belirtilmedi');
    }
    
    $batch_results = [];
    $processed = 0;
    
    // Batch operasyonları gruplar halinde işle
    $operation_chunks = array_chunk($batch_operations, $max_concurrent);
    
    foreach ($operation_chunks as $chunk) {
        $chunk_results = [];
        
        foreach ($chunk as $operation) {
            try {
                $result = processDistributionOperation($operation);
                $chunk_results[] = [
                    'operation' => $operation,
                    'success' => $result['success'],
                    'message' => $result['message'],
                    'processed_at' => date('Y-m-d H:i:s')
                ];
                $processed++;
                
            } catch (Exception $e) {
                $chunk_results[] = [
                    'operation' => $operation,
                    'success' => false,
                    'message' => $e->getMessage(),
                    'processed_at' => date('Y-m-d H:i:s')
                ];
            }
        }
        
        $batch_results[] = $chunk_results;
        
        // Kısa bekleme (sunucu yükünü azaltmak için)
        usleep(500000); // 0.5 saniye
    }
    
    return [
        'success' => true,
        'message' => "Batch dağıtım tamamlandı: {$processed} operasyon işlendi",
        'total_operations' => count($batch_operations),
        'processed_operations' => $processed,
        'batch_results' => $batch_results,
        'completed_at' => date('Y-m-d H:i:s')
    ];
}

/**
 * Öncelik bazlı dağıtım
 */
function performPriorityDistribution() {
    global $conn;
    
    // Yüksek öncelikli dağıtım kuyruğunu al
    $stmt = $conn->query("
        SELECT * FROM sync_queue 
        WHERE status = 'pending' 
        AND priority <= 3
        ORDER BY priority ASC, created_at ASC
        LIMIT 50
    ");
    $priority_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($priority_items)) {
        return [
            'success' => true,
            'message' => 'Yüksek öncelikli dağıtım öğesi yok',
            'processed_count' => 0
        ];
    }
    
    $distribution_results = [];
    $success_count = 0;
    $failed_count = 0;
    
    foreach ($priority_items as $item) {
        try {
            // Kuyruktaki öğeyi işle
            $result = processPriorityQueueItem($item);
            
            $distribution_results[] = [
                'queue_id' => $item['id'],
                'operation_type' => $item['operation_type'],
                'magaza_id' => $item['magaza_id'],
                'priority' => $item['priority'],
                'success' => $result['success'],
                'message' => $result['message']
            ];
            
            if ($result['success']) {
                $success_count++;
                
                // Başarılı öğeyi kuyruktan kaldır
                $stmt = $conn->prepare("
                    UPDATE sync_queue 
                    SET status = 'completed', processed_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$item['id']]);
            } else {
                $failed_count++;
                
                // Başarısız öğeyi işaretle
                $stmt = $conn->prepare("
                    UPDATE sync_queue 
                    SET status = 'failed', attempts = attempts + 1, error_message = ?
                    WHERE id = ?
                ");
                $stmt->execute([$result['error'], $item['id']]);
            }
            
        } catch (Exception $e) {
            $failed_count++;
            
            $distribution_results[] = [
                'queue_id' => $item['id'],
                'operation_type' => $item['operation_type'],
                'magaza_id' => $item['magaza_id'],
                'priority' => $item['priority'],
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    return [
        'success' => true,
        'message' => "Öncelik dağıtımı tamamlandı: {$success_count} başarılı, {$failed_count} başarısız",
        'total_items' => count($priority_items),
        'success_count' => $success_count,
        'failed_count' => $failed_count,
        'distribution_results' => $distribution_results
    ];
}

// ==================== YARDIMCI FONKSİYONLARI ====================

/**
 * Hedef mağazaları belirle
 */
function getTargetStores($target_stores) {
    global $conn;
    
    if ($target_stores === 'all') {
        $stmt = $conn->query("
            SELECT m.id, m.ad,
                   COALESCE(sc.config_value, 'DIRECT') as operation_mode,
                   CASE 
                       WHEN m.id = 1 AND COALESCE(sc.config_value, 'SYNC') = 'SYNC' 
                            THEN 'https://merkez.incikirtasiye.com/sync/receive_webhook.php'
                       WHEN m.id = 2 AND COALESCE(sc.config_value, 'DIRECT') = 'SYNC' 
                            THEN 'https://dolunay.incikirtasiye.com/sync/receive_webhook.php'
                       ELSE NULL
                   END as webhook_url
            FROM magazalar m
            LEFT JOIN store_config sc ON m.id = sc.magaza_id AND sc.config_key = 'operation_mode'
            WHERE m.id IN (1, 2)
        ");
    } else if (is_array($target_stores)) {
        $ids = array_map('intval', $target_stores);
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        
        $stmt = $conn->prepare("
            SELECT m.id, m.ad,
                   COALESCE(sc.config_value, 'DIRECT') as operation_mode,
                   CASE 
                       WHEN m.id = 1 AND COALESCE(sc.config_value, 'SYNC') = 'SYNC' 
                            THEN 'https://merkez.incikirtasiye.com/sync/receive_webhook.php'
                       WHEN m.id = 2 AND COALESCE(sc.config_value, 'DIRECT') = 'SYNC' 
                            THEN 'https://dolunay.incikirtasiye.com/sync/receive_webhook.php'
                       ELSE NULL
                   END as webhook_url
            FROM magazalar m
            LEFT JOIN store_config sc ON m.id = sc.magaza_id AND sc.config_key = 'operation_mode'
            WHERE m.id IN ($placeholders)
        ");
        $stmt->execute($ids);
    }
    
    $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // SYNC modda olan mağazaları filtrele (webhook gönderilecek)
    return array_filter($stores, function($store) {
        return !is_null($store['webhook_url']);
    });
}

/**
 * Mağazaya veri gönder
 */
function sendDataToStore($store, $data_type, $data) {
    if (!$store['webhook_url']) {
        return [
            'success' => false,
            'message' => 'DIRECT modda webhook gönderilmez'
        ];
    }
    
    $webhook_secret = 'pos_webhook_secret_2024';
    
    $payload = [
        'type' => $data_type,
        'data' => $data,
        'timestamp' => time(),
        'source' => 'data_distributor'
    ];
    
    $json_payload = json_encode($payload);
    $signature = hash_hmac('sha256', $json_payload, $webhook_secret);
    
    $headers = [
        'Content-Type: application/json',
        'X-Webhook-Signature: ' . $signature,
        'X-Source: pos-data-distributor',
        'X-Store-ID: ' . $store['id'],
        'X-Distribution-Priority: ' . ($data['priority'] ?? 'normal'),
        'User-Agent: POS-Data-Distributor/2.1'
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $store['webhook_url'],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json_payload,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("Data distribution webhook hatası ({$store['webhook_url']}): $error");
        return ['success' => false, 'message' => "Connection error: $error"];
    }
    
    if ($http_code !== 200) {
        error_log("Data distribution HTTP hatası ({$store['webhook_url']}): HTTP $http_code");
        return ['success' => false, 'message' => "HTTP error: $http_code"];
    }
    
    $response_data = json_decode($response, true);
    return $response_data ?: ['success' => true, 'message' => 'Data sent successfully'];
}

/**
 * Son sync zamanını al
 */
function getLastSyncTime($data_type) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT MAX(son_sync_tarihi) 
        FROM sync_metadata 
        WHERE tablo_adi = ?
    ");
    $stmt->execute([$data_type]);
    
    return $stmt->fetchColumn() ?: '1970-01-01 00:00:00';
}

/**
 * Son sync zamanını güncelle
 */
function updateLastSyncTime($data_type) {
    global $conn;
    
    $stmt = $conn->prepare("
        INSERT INTO sync_metadata (magaza_id, tablo_adi, son_sync_tarihi, sync_durumu)
        VALUES (0, ?, NOW(), 'basarili')
        ON DUPLICATE KEY UPDATE
        son_sync_tarihi = NOW(),
        sync_durumu = 'basarili'
    ");
    $stmt->execute([$data_type]);
}

/**
 * Dağıtım sonuçlarını özetle
 */
function summarizeDistributionResults($distribution_results) {
    $summary = [
        'total_distributions' => 0,
        'successful_distributions' => 0,
        'failed_distributions' => 0,
        'data_types_processed' => [],
        'success_rate' => 0
    ];
    
    foreach ($distribution_results as $data_type => $result) {
        $summary['data_types_processed'][] = $data_type;
        
        if (isset($result['distribution_results'])) {
            foreach ($result['distribution_results'] as $store_result) {
                $summary['total_distributions']++;
                
                if ($store_result['success']) {
                    $summary['successful_distributions']++;
                } else {
                    $summary['failed_distributions']++;
                }
            }
        }
    }
    
    if ($summary['total_distributions'] > 0) {
        $summary['success_rate'] = round(
            ($summary['successful_distributions'] / $summary['total_distributions']) * 100, 2
        );
    }
    
    return $summary;
}

/**
 * Dağıtım kuyruk durumunu al
 */
function getDistributionQueueStatus() {
    global $conn;
    
    // Kuyruk istatistikleri
    $stmt = $conn->query("
        SELECT 
            status,
            priority,
            COUNT(*) as count
        FROM sync_queue 
        GROUP BY status, priority
        ORDER BY priority ASC, status
    ");
    $queue_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Bekleyen yüksek öncelikli öğeler
    $stmt = $conn->query("
        SELECT 
            operation_type,
            magaza_id,
            priority,
            created_at,
            attempts
        FROM sync_queue 
        WHERE status = 'pending' AND priority <= 3
        ORDER BY priority ASC, created_at ASC
        LIMIT 20
    ");
    $priority_queue = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Son 24 saatteki dağıtım performansı
    $stmt = $conn->query("
        SELECT 
            DATE_FORMAT(processed_at, '%H:00') as hour,
            COUNT(*) as processed_count,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_count
        FROM sync_queue 
        WHERE processed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY DATE_FORMAT(processed_at, '%H:00')
        ORDER BY hour DESC
    ");
    $hourly_performance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'success' => true,
        'message' => 'Kuyruk durumu alındı',
        'queue_statistics' => $queue_stats,
        'priority_queue' => $priority_queue,
        'hourly_performance' => $hourly_performance,
        'generated_at' => date('Y-m-d H:i:s')
    ];
}

/**
 * Tam senkronizasyonu zorla
 */
function forceFullSynchronization() {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $target_stores = $input['target_stores'] ?? 'all';
    $include_historical = $input['include_historical'] ?? false;
    
    try {
        $sync_results = [];
        
        // Tüm merkezi verileri zorla dağıt
        $sync_results['customer'] = distributeCentralCustomerData($target_stores, 'high');
        $sync_results['invoice'] = distributeCentralInvoiceData($target_stores, 'high');
        $sync_results['points'] = distributeCentralPointsData($target_stores, 'high');
        $sync_results['credits'] = distributeCentralCreditsData($target_stores, 'high');
        $sync_results['system'] = distributeSystemSettings($target_stores, 'high');
        $sync_results['stock'] = coordinateStockData($target_stores, 'high');
        
        // Geçmiş verileri de dahil et (isteğe bağlı)
        if ($include_historical) {
            $sync_results['historical'] = distributeHistoricalData($target_stores);
        }
        
        return [
            'success' => true,
            'message' => 'Tam senkronizasyon tamamlandı',
            'target_stores' => $target_stores,
            'include_historical' => $include_historical,
            'sync_results' => $sync_results,
            'completed_at' => date('Y-m-d H:i:s')
        ];
        
    } catch (Exception $e) {
        throw new Exception('Tam senkronizasyon hatası: ' . $e->getMessage());
    }
}

/**
 * Dağıtım istatistikleri al
 */
function getDistributionStatistics() {
    global $conn;
    
    // Son 30 günlük dağıtım istatistikleri
    $stmt = $conn->query("
        SELECT 
            operation_type,
            status,
            COUNT(*) as count,
            AVG(TIMESTAMPDIFF(SECOND, created_at, COALESCE(processed_at, NOW()))) as avg_processing_time
        FROM sync_queue 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY operation_type, status
        ORDER BY operation_type, status
    ");
    $operation_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Mağaza bazlı performans
    $stmt = $conn->query("
        SELECT 
            magaza_id,
            COUNT(*) as total_operations,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_operations,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_operations,
            AVG(attempts) as avg_attempts
        FROM sync_queue 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY magaza_id
        ORDER BY magaza_id
    ");
    $store_performance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Günlük dağıtım trendi
    $stmt = $conn->query("
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as total_operations,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_operations
        FROM sync_queue 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date DESC
        LIMIT 30
    ");
    $daily_trend = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'success' => true,
        'message' => 'Dağıtım istatistikleri alındı',
        'operation_statistics' => $operation_stats,
        'store_performance' => $store_performance,
        'daily_trend' => $daily_trend,
        'period' => 'Son 30 gün',
        'generated_at' => date('Y-m-d H:i:s')
    ];
}

/**
 * Tüm endpoint'leri test et
 */
function testAllEndpoints() {
    $stores = getTargetStores('all');
    
    $test_results = [];
    
    foreach ($stores as $store) {
        $endpoint_tests = [];
        
        // Ping test
        $ping_result = testEndpointConnectivity($store['webhook_url']);
        $endpoint_tests['connectivity'] = $ping_result;
        
        // Webhook test
        $webhook_result = testWebhookEndpoint($store);
        $endpoint_tests['webhook'] = $webhook_result;
        
        $test_results[] = [
            'store_id' => $store['id'],
            'store_name' => $store['ad'],
            'webhook_url' => $store['webhook_url'],
            'tests' => $endpoint_tests,
            'overall_status' => ($ping_result['success'] && $webhook_result['success']) ? 'healthy' : 'unhealthy'
        ];
    }
    
    return [
        'success' => true,
        'message' => 'Endpoint testleri tamamlandı',
        'test_results' => $test_results,
        'tested_stores' => count($stores),
        'tested_at' => date('Y-m-d H:i:s')
    ];
}

/**
 * Endpoint bağlantı testi
 */
function testEndpointConnectivity($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => false
    ]);
    
    $start_time = microtime(true);
    curl_exec($ch);
    $response_time = round((microtime(true) - $start_time) * 1000, 2);
    
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    return [
        'success' => !$error && $http_code > 0,
        'http_code' => $http_code,
        'response_time_ms' => $response_time,
        'error' => $error ?: null
    ];
}

/**
 * Webhook endpoint testi
 */
function testWebhookEndpoint($store) {
    $test_data = [
        'type' => 'health_check',
        'data' => [
            'test' => true,
            'timestamp' => time()
        ],
        'source' => 'data_distributor_test'
    ];
    
    return sendDataToStore($store, 'health_check', $test_data);
}

/**
 * Dağıtım sağlık kontrolü
 */
function performDistributionHealthCheck() {
    global $conn;
    
    $health_status = [
        'overall_status' => 'healthy',
        'issues' => [],
        'warnings' => [],
        'statistics' => []
    ];
    
    // Kuyruk sağlığı kontrolü
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total_pending,
            COUNT(CASE WHEN priority <= 2 THEN 1 END) as critical_pending,
            COUNT(CASE WHEN attempts >= 3 THEN 1 END) as failed_items,
            MAX(created_at) as latest_item
        FROM sync_queue 
        WHERE status = 'pending'
    ");
    $queue_health = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Kritik durumları kontrol et
    if ($queue_health['critical_pending'] > 10) {
        $health_status['issues'][] = "Yüksek öncelikli {$queue_health['critical_pending']} öğe beklemede";
        $health_status['overall_status'] = 'critical';
    }
    
    if ($queue_health['failed_items'] > 20) {
        $health_status['issues'][] = "Çok fazla başarısız öğe: {$queue_health['failed_items']}";
        $health_status['overall_status'] = 'warning';
    }
    
    if ($queue_health['total_pending'] > 100) {
        $health_status['warnings'][] = "Kuyrukta çok fazla bekleyen öğe: {$queue_health['total_pending']}";
    }
    
    // Mağaza bağlantı sağlığı
    $stores = getTargetStores('all');
    $unhealthy_stores = 0;
    
    foreach ($stores as $store) {
        $connectivity = testEndpointConnectivity($store['webhook_url']);
        if (!$connectivity['success'] || $connectivity['response_time_ms'] > 5000) {
            $unhealthy_stores++;
            $health_status['issues'][] = "Mağaza {$store['ad']} bağlantı sorunu";
        }
    }
    
    if ($unhealthy_stores > 0) {
        $health_status['overall_status'] = 'warning';
    }
    
    $health_status['statistics'] = [
        'queue_health' => $queue_health,
        'total_stores' => count($stores),
        'unhealthy_stores' => $unhealthy_stores,
        'healthy_stores' => count($stores) - $unhealthy_stores
    ];
    
    return [
        'success' => true,
        'message' => 'Dağıtım sağlık kontrolü tamamlandı',
        'health_status' => $health_status,
        'checked_at' => date('Y-m-d H:i:s')
    ];
}

/**
 * Dağıtım operasyonunu işle
 */
function processDistributionOperation($operation) {
    switch ($operation['type']) {
        case 'customer_sync':
            return distributeCentralCustomerData($operation['target_stores'], $operation['priority']);
            
        case 'invoice_sync':
            return distributeCentralInvoiceData($operation['target_stores'], $operation['priority']);
            
        case 'points_sync':
            return distributeCentralPointsData($operation['target_stores'], $operation['priority']);
            
        case 'credits_sync':
            return distributeCentralCreditsData($operation['target_stores'], $operation['priority']);
            
        case 'stock_sync':
            return coordinateStockData($operation['target_stores'], $operation['priority']);
            
        case 'system_sync':
            return distributeSystemSettings($operation['target_stores'], $operation['priority']);
            
        default:
            throw new Exception('Bilinmeyen operasyon türü: ' . $operation['type']);
    }
}

/**
 * Öncelik kuyruk öğesini işle
 */
function processPriorityQueueItem($item) {
    $data = json_decode($item['data_json'], true);
    
    switch ($item['operation_type']) {
        case 'sale':
            return processSaleDistribution($item['magaza_id'], $data);
            
        case 'stock_update':
            return processStockUpdateDistribution($item['magaza_id'], $data);
            
        case 'customer_update':
            return processCustomerUpdateDistribution($item['magaza_id'], $data);
            
        case 'price_update':
            return processPriceUpdateDistribution($item['magaza_id'], $data);
            
        default:
            throw new Exception('Bilinmeyen kuyruk operasyonu: ' . $item['operation_type']);
    }
}

/**
 * Satış dağıtımını işle
 */
function processSaleDistribution($source_magaza_id, $sale_data) {
    // Satış verisini diğer mağazalara dağıt
    $target_stores = getTargetStores('all');
    
    foreach ($target_stores as $store) {
        if ($store['id'] != $source_magaza_id) {
            $result = sendDataToStore($store, 'sale_notification', [
                'sale_data' => $sale_data,
                'source_store' => $source_magaza_id,
                'priority' => 'high'
            ]);
        }
    }
    
    return ['success' => true, 'message' => 'Satış dağıtımı tamamlandı'];
}

/**
 * Stok güncelleme dağıtımını işle
 */
function processStockUpdateDistribution($source_magaza_id, $stock_data) {
    // Stok güncellemesini diğer mağazalara bildir
    $target_stores = getTargetStores('all');
    
    foreach ($target_stores as $store) {
        if ($store['id'] != $source_magaza_id) {
            $result = sendDataToStore($store, 'stock_notification', [
                'stock_data' => $stock_data,
                'source_store' => $source_magaza_id,
                'priority' => 'normal'
            ]);
        }
    }
    
    return ['success' => true, 'message' => 'Stok güncelleme dağıtımı tamamlandı'];
}

/**
 * Müşteri güncelleme dağıtımını işle
 */
function processCustomerUpdateDistribution($source_magaza_id, $customer_data) {
    // Müşteri güncellemesini tüm mağazalara dağıt
    $target_stores = getTargetStores('all');
    
    foreach ($target_stores as $store) {
        $result = sendDataToStore($store, 'customer_notification', [
            'customer_data' => $customer_data,
            'source_store' => $source_magaza_id,
            'priority' => 'normal'
        ]);
    }
    
    return ['success' => true, 'message' => 'Müşteri güncelleme dağıtımı tamamlandı'];
}

/**
 * Fiyat güncelleme dağıtımını işle
 */
function processPriceUpdateDistribution($source_magaza_id, $price_data) {
    // Fiyat güncellemesini tüm mağazalara dağıt
    $target_stores = getTargetStores('all');
    
    foreach ($target_stores as $store) {
        $result = sendDataToStore($store, 'price_notification', [
            'price_data' => $price_data,
            'source_store' => $source_magaza_id,
            'priority' => 'high'
        ]);
    }
    
    return ['success' => true, 'message' => 'Fiyat güncelleme dağıtımı tamamlandı'];
}

/**
 * Geçmiş verileri dağıt
 */
function distributeHistoricalData($target_stores) {
    global $conn;
    
    // Son 3 ayın verilerini al
    $three_months_ago = date('Y-m-d', strtotime('-3 months'));
    
    $historical_data = [
        'start_date' => $three_months_ago,
        'end_date' => date('Y-m-d'),
        'data_types' => ['invoices', 'customer_changes', 'point_transactions']
    ];
    
    // Hedef mağazaları belirle
    $stores = getTargetStores($target_stores);
    
    $distribution_results = [];
    
    foreach ($stores as $store) {
        $result = sendDataToStore($store, 'historical_sync', [
            'historical_data' => $historical_data,
            'update_type' => 'full_historical',
            'priority' => 'low'
        ]);
        
        $distribution_results[] = [
            'store_id' => $store['id'],
            'store_name' => $store['ad'],
            'success' => $result['success'],
            'message' => $result['message'] ?? 'Geçmiş veri dağıtımı'
        ];
    }
    
    return [
        'success' => true,
        'message' => 'Geçmiş veriler dağıtıldı',
        'period' => $three_months_ago . ' - ' . date('Y-m-d'),
        'target_stores' => count($stores),
        'distribution_results' => $distribution_results
    ];
}

/**
 * Otomatik dağıtım scheduler
 */
function scheduleAutomaticDistribution() {
    global $conn;
    
    // Real-time dağıtım gerektiren değişiklikleri kontrol et
    $real_time_changes = checkRealTimeChanges();
    
    if (!empty($real_time_changes)) {
        foreach ($real_time_changes as $change) {
            addToDistributionQueue($change);
        }
    }
    
    // Acil sync gerektiren değişiklikleri kontrol et (5-15 dakika)
    $urgent_changes = checkUrgentChanges();
    
    if (!empty($urgent_changes)) {
        foreach ($urgent_changes as $change) {
            addToDistributionQueue($change, 'urgent');
        }
    }
    
    return [
        'success' => true,
        'real_time_changes' => count($real_time_changes),
        'urgent_changes' => count($urgent_changes),
        'scheduled_at' => date('Y-m-d H:i:s')
    ];
}

/**
 * Real-time değişiklikleri kontrol et
 */
function checkRealTimeChanges() {
    global $conn;
    
    $changes = [];
    $last_check = date('Y-m-d H:i:s', strtotime('-5 minutes'));
    
    // Yeni satışlar
    $stmt = $conn->prepare("
        SELECT * FROM satis_faturalari 
        WHERE last_updated > ? AND sync_durumu = 0
    ");
    $stmt->execute([$last_check]);
    $new_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($new_sales as $sale) {
        $changes[] = [
            'type' => 'sale',
            'data' => $sale,
            'priority' => 'critical',
            'magaza_id' => $sale['magaza']
        ];
    }
    
    // Yeni müşteriler
    $stmt = $conn->prepare("
        SELECT * FROM musteriler 
        WHERE last_updated > ?
    ");
    $stmt->execute([$last_check]);
    $new_customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($new_customers as $customer) {
        $changes[] = [
            'type' => 'customer',
            'data' => $customer,
            'priority' => 'normal',
            'magaza_id' => 0 // Merkezi veri
        ];
    }
    
    return $changes;
}

/**
 * Acil değişiklikleri kontrol et
 */
function checkUrgentChanges() {
    global $conn;
    
    $changes = [];
    $last_check = date('Y-m-d H:i:s', strtotime('-15 minutes'));
    
    // Fiyat değişiklikleri
    $stmt = $conn->prepare("
        SELECT * FROM urun_stok 
        WHERE last_updated > ?
    ");
    $stmt->execute([$last_check]);
    $price_changes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($price_changes as $product) {
        $changes[] = [
            'type' => 'price_update',
            'data' => $product,
            'priority' => 'urgent',
            'magaza_id' => 0 // Merkezi veri
        ];
    }
    
    // Sistem ayarları değişiklikleri
    $stmt = $conn->prepare("
        SELECT * FROM sistem_ayarlari 
        WHERE guncelleme_tarihi > ?
    ");
    $stmt->execute([$last_check]);
    $system_changes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($system_changes as $setting) {
        $changes[] = [
            'type' => 'system_setting',
            'data' => $setting,
            'priority' => 'urgent',
            'magaza_id' => 0 // Merkezi veri
        ];
    }
    
    return $changes;
}

/**
 * Dağıtım kuyruğuna ekle
 */
function addToDistributionQueue($change, $priority = 'normal') {
    global $conn;
    
    $priority_map = [
        'critical' => 1,
        'urgent' => 2,
        'normal' => 3,
        'low' => 4
    ];
    
    $priority_number = $priority_map[$priority] ?? 3;
    
    $stmt = $conn->prepare("
        INSERT INTO sync_queue (
            magaza_id, operation_type, table_name, record_id, 
            data_json, priority, created_at, scheduled_at, status
        ) VALUES (
            ?, ?, ?, ?, ?, ?, NOW(), NOW(), 'pending'
        )
    ");
    
    $stmt->execute([
        $change['magaza_id'],
        $change['type'],
        $change['table_name'] ?? '',
        $change['record_id'] ?? null,
        json_encode($change['data']),
        $priority_number
    ]);
    
    return $conn->lastInsertId();
}

/**
 * Hibrit mimari veri senkronizasyonu
 * Merkezi ve dağıtık verileri koordine eder
 */
function performHybridSync() {
    $results = [
        'central_data' => [],
        'distributed_data' => [],
        'coordination_results' => []
    ];
    
    // MERKEZI VERİLER - Tüm mağazalara dağıt
    $central_data_types = ['customer', 'points', 'credits', 'invoices', 'system_settings'];
    
    foreach ($central_data_types as $data_type) {
        switch ($data_type) {
            case 'customer':
                $results['central_data'][$data_type] = distributeCentralCustomerData('all', 'normal');
                break;
            case 'points':
                $results['central_data'][$data_type] = distributeCentralPointsData('all', 'normal');
                break;
            case 'credits':
                $results['central_data'][$data_type] = distributeCentralCreditsData('all', 'normal');
                break;
            case 'invoices':
                $results['central_data'][$data_type] = distributeCentralInvoiceData('all', 'normal');
                break;
            case 'system_settings':
                $results['central_data'][$data_type] = distributeSystemSettings('all', 'normal');
                break;
        }
    }
    
    // DAĞITIK VERİLER - Koordinasyon
    $results['distributed_data']['stock'] = coordinateStockData('all', 'normal');
    
    // Hibrit senkronizasyon sonucu
    return [
        'success' => true,
        'message' => 'Hibrit senkronizasyon tamamlandı',
        'sync_type' => 'hybrid',
        'results' => $results,
        'completed_at' => date('Y-m-d H:i:s')
    ];
}

/**
 * Mağaza modunu kontrol et ve webhook URL'ini belirle
 */
function determineStoreWebhookUrl($store_id) {
    global $conn;
    
    // Mağaza konfigürasyonunu al
    $stmt = $conn->prepare("
        SELECT sc.config_value as operation_mode
        FROM store_config sc
        WHERE sc.magaza_id = ? AND sc.config_key = 'operation_mode'
    ");
    $stmt->execute([$store_id]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $operation_mode = $config['operation_mode'] ?? 'DIRECT';
    
    // SYNC modda olan mağazalar için webhook URL'ini belirle
    if ($operation_mode === 'SYNC') {
        switch ($store_id) {
            case 1: // Merkez Mağaza
                return 'https://merkez.incikirtasiye.com/sync/receive_webhook.php';
            case 2: // Dolunay Mağaza
                return 'https://dolunay.incikirtasiye.com/sync/receive_webhook.php';
            default:
                return null;
        }
    }
    
    // DIRECT modda webhook gönderilmez
    return null;
}

/**
 * Veri dağıtım performansını optimize et
 */
function optimizeDistributionPerformance() {
    global $conn;
    
    $optimization_results = [];
    
    // 1. Eski kuyruk öğelerini temizle
    $stmt = $conn->prepare("
        DELETE FROM sync_queue 
        WHERE status = 'completed' 
        AND processed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $stmt->execute();
    $cleaned_completed = $stmt->rowCount();
    
    // 2. Başarısız öğeleri analiz et ve temizle
    $stmt = $conn->prepare("
        SELECT COUNT(*) as failed_count 
        FROM sync_queue 
        WHERE status = 'failed' 
        AND attempts >= 3
        AND created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)
    ");
    $stmt->execute();
    $failed_items = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Çok eski başarısız öğeleri sil
    $stmt = $conn->prepare("
        DELETE FROM sync_queue 
        WHERE status = 'failed' 
        AND attempts >= 3
        AND created_at < DATE_SUB(NOW(), INTERVAL 3 DAY)
    ");
    $stmt->execute();
    $cleaned_failed = $stmt->rowCount();
    
    // 3. Sync metadata tablosunu optimize et
    $stmt = $conn->prepare("
        DELETE FROM sync_metadata 
        WHERE son_sync_tarihi < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stmt->execute();
    $cleaned_metadata = $stmt->rowCount();
    
    $optimization_results = [
        'cleaned_completed_items' => $cleaned_completed,
        'failed_items_count' => $failed_items['failed_count'],
        'cleaned_failed_items' => $cleaned_failed,
        'cleaned_metadata_records' => $cleaned_metadata
    ];
    
    return [
        'success' => true,
        'message' => 'Dağıtım performansı optimize edildi',
        'optimization_results' => $optimization_results,
        'optimized_at' => date('Y-m-d H:i:s')
    ];
}

/**
 * Acil durum senkronizasyonu
 * Kritik sistem sorunları için hızlı müdahale
 */
function emergencySync() {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $emergency_type = $input['emergency_type'] ?? 'full';
    $target_stores = $input['target_stores'] ?? 'all';
    
    $emergency_results = [];
    
    switch ($emergency_type) {
        case 'critical_data':
            // Sadece kritik verileri dağıt
            $emergency_results['customers'] = distributeCentralCustomerData($target_stores, 'critical');
            $emergency_results['credits'] = distributeCentralCreditsData($target_stores, 'critical');
            break;
            
        case 'stock_only':
            // Sadece stok verilerini koordine et
            $emergency_results['stock'] = coordinateStockData($target_stores, 'critical');
            break;
            
        case 'full':
        default:
            // Tüm verileri acil dağıt
            $emergency_results = performHybridSync();
            break;
    }
    
    return [
        'success' => true,
        'message' => 'Acil durum senkronizasyonu tamamlandı',
        'emergency_type' => $emergency_type,
        'target_stores' => $target_stores,
        'emergency_results' => $emergency_results,
        'completed_at' => date('Y-m-d H:i:s')
    ];
}

/**
 * Senkronizasyon raporları oluştur
 */
function generateSyncReports() {
    global $conn;
    
    $report_data = [];
    
    // Son 24 saatin özeti
    $stmt = $conn->query("
        SELECT 
            DATE_FORMAT(created_at, '%H:00') as hour,
            operation_type,
            status,
            COUNT(*) as count
        FROM sync_queue 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY DATE_FORMAT(created_at, '%H:00'), operation_type, status
        ORDER BY hour DESC, operation_type, status
    ");
    $report_data['hourly_summary'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Mağaza performans raporu
    $stmt = $conn->query("
        SELECT 
            magaza_id,
            COUNT(*) as total_operations,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
            AVG(TIMESTAMPDIFF(SECOND, created_at, processed_at)) as avg_processing_time
        FROM sync_queue 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY magaza_id
        ORDER BY magaza_id
    ");
    $report_data['store_performance'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // En sık kullanılan operasyonlar
    $stmt = $conn->query("
        SELECT 
            operation_type,
            COUNT(*) as count,
            AVG(attempts) as avg_attempts,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as success_count
        FROM sync_queue 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY operation_type
        ORDER BY count DESC
        LIMIT 10
    ");
    $report_data['top_operations'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Hata analizi
    $stmt = $conn->query("
        SELECT 
            error_message,
            COUNT(*) as error_count,
            MAX(created_at) as last_occurrence
        FROM sync_queue 
        WHERE status = 'failed' 
        AND error_message IS NOT NULL
        AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY error_message
        ORDER BY error_count DESC
        LIMIT 5
    ");
    $report_data['error_analysis'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'success' => true,
        'message' => 'Senkronizasyon raporları oluşturuldu',
        'report_period' => 'Son 7 gün',
        'report_data' => $report_data,
        'generated_at' => date('Y-m-d H:i:s')
    ];
}

/**
 * Webhook güvenlik doğrulaması
 */
function validateWebhookSecurity($payload, $signature, $secret) {
    $expected_signature = hash_hmac('sha256', $payload, $secret);
    
    return hash_equals($expected_signature, $signature);
}

/**
 * Rate limiting kontrolü
 */
function checkRateLimit($store_id, $operation_type) {
    global $conn;
    
    // Son 1 dakikadaki istek sayısını kontrol et
    $stmt = $conn->prepare("
        SELECT COUNT(*) as request_count
        FROM sync_queue 
        WHERE magaza_id = ? 
        AND operation_type = ?
        AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
    ");
    $stmt->execute([$store_id, $operation_type]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Dakikada maksimum 60 istek
    return $result['request_count'] < 60;
}

/**
 * Cron job için otomatik dağıtım
 */
function cronDistribution() {
    // Zamanlanmış otomatik dağıtım işlemlerini çalıştır
    $results = [];
    
    // 1. Real-time değişiklikleri kontrol et
    $results['real_time'] = scheduleAutomaticDistribution();
    
    // 2. Bekleyen yüksek öncelikli işlemleri çalıştır
    $results['priority'] = performPriorityDistribution();
    
    // 3. Performans optimizasyonu (sadece gece saatlerinde)
    $current_hour = (int)date('H');
    if ($current_hour >= 2 && $current_hour <= 4) {
        $results['optimization'] = optimizeDistributionPerformance();
    }
    
    // 4. Sağlık kontrolü
    $results['health_check'] = performDistributionHealthCheck();
    
    return [
        'success' => true,
        'message' => 'Cron dağıtım işlemleri tamamlandı',
        'cron_results' => $results,
        'executed_at' => date('Y-m-d H:i:s')
    ];
}

?>