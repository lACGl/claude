<?php
/**
 * MERKEZ MAĞAZA - SYNC KUYRUK YÖNETİCİSİ
 * Senkronizasyon kuyruğunu yönetir, başarısız işlemleri tekrar dener
 * Hibrit Sync - Phase 1: Queue management and retry logic
 */

require_once '../../session_manager.php';
require_once '../../db_connection.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Güvenlik kontrolü - sadece cron veya admin erişimi
$is_cron = (php_sapi_name() === 'cli' || isset($_GET['cron_key']));
$is_admin = (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true);

if (!$is_cron && !$is_admin) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Yetkisiz erişim']));
}

// Konfigürasyon
$STORE_ID = 1; // Merkez mağaza
$MAX_RETRY_ATTEMPTS = 3;
$RETRY_DELAY_MINUTES = [5, 15, 60]; // İlk hata: 5 dk, ikinci: 15 dk, üçüncü: 60 dk
$MAIN_SERVER_URL = 'https://pos.incikirtasiye.com/admin/api/sync/receive_sales.php';
$API_KEY = 'merkez_sync_key_2025';

$response = [
    'success' => false,
    'message' => '',
    'queue_stats' => [],
    'processed_items' => [],
    'failed_items' => []
];

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? 'process_queue';
    
    switch ($action) {
        case 'process_queue':
            $result = processQueue();
            break;
            
        case 'retry_failed':
            $result = retryFailedItems();
            break;
            
        case 'get_queue_status':
            $result = getQueueStatus();
            break;
            
        case 'clean_old_records':
            $result = cleanOldRecords();
            break;
            
        case 'prioritize_item':
            $item_id = $_POST['item_id'] ?? null;
            $priority = $_POST['priority'] ?? 5;
            $result = prioritizeItem($item_id, $priority);
            break;
            
        case 'cancel_item':
            $item_id = $_POST['item_id'] ?? null;
            $result = cancelItem($item_id);
            break;
            
        case 'get_retry_schedule':
            $result = getRetrySchedule();
            break;
            
        default:
            throw new Exception("Desteklenmeyen aksiyon: $action");
    }
    
    $response = array_merge($response, $result);
    $response['success'] = true;
    
} catch (Exception $e) {
    $response['message'] = 'Queue Manager Error: ' . $e->getMessage();
    error_log('Queue Manager Error: ' . $e->getMessage());
    http_response_code(500);
}

echo json_encode($response);

/**
 * Ana kuyruk işleme fonksiyonu
 */
function processQueue() {
    global $conn, $STORE_ID, $MAX_RETRY_ATTEMPTS;
    
    $result = [
        'message' => '',
        'queue_stats' => [],
        'processed_items' => [],
        'failed_items' => []
    ];
    
    try {
        // Öncelik sırasına göre bekleyen öğeleri getir
        $stmt = $conn->prepare("
            SELECT * FROM sync_queue 
            WHERE magaza_id = :magaza_id 
                AND status IN ('pending', 'failed')
                AND attempts < :max_attempts
                AND (scheduled_at IS NULL OR scheduled_at <= NOW())
            ORDER BY priority ASC, created_at ASC
            LIMIT 20
        ");
        
        $stmt->execute([
            ':magaza_id' => $STORE_ID,
            ':max_attempts' => $MAX_RETRY_ATTEMPTS
        ]);
        
        $queue_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($queue_items)) {
            $result['message'] = 'İşlenecek kuyruk öğesi bulunamadı';
            $result['queue_stats'] = getQueueStatistics();
            return $result;
        }
        
        // Her öğeyi işle
        foreach ($queue_items as $item) {
            try {
                // İşlem durumunu güncelle
                updateQueueItemStatus($item['id'], 'processing');
                
                // Operasyon tipine göre işle
                $process_result = processQueueItem($item);
                
                if ($process_result['success']) {
                    // Başarılı - tamamlandı olarak işaretle
                    updateQueueItemStatus($item['id'], 'completed', null, $process_result['message']);
                    
                    $result['processed_items'][] = [
                        'id' => $item['id'],
                        'operation' => $item['operation_type'],
                        'status' => 'completed',
                        'message' => $process_result['message']
                    ];
                    
                } else {
                    // Başarısız - tekrar dene
                    $next_attempt = $item['attempts'] + 1;
                    
                    if ($next_attempt < $MAX_RETRY_ATTEMPTS) {
                        scheduleRetry($item['id'], $next_attempt, $process_result['message']);
                        
                        $result['failed_items'][] = [
                            'id' => $item['id'],
                            'operation' => $item['operation_type'],
                            'status' => 'scheduled_retry',
                            'attempt' => $next_attempt,
                            'message' => $process_result['message']
                        ];
                    } else {
                        // Maksimum deneme sayısına ulaşıldı
                        updateQueueItemStatus($item['id'], 'failed', $process_result['message']);
                        
                        $result['failed_items'][] = [
                            'id' => $item['id'],
                            'operation' => $item['operation_type'],
                            'status' => 'permanently_failed',
                            'message' => $process_result['message']
                        ];
                    }
                }
                
                // Rate limiting
                usleep(200000); // 0.2 saniye
                
            } catch (Exception $e) {
                // Kritik hata
                updateQueueItemStatus($item['id'], 'failed', 'Critical error: ' . $e->getMessage());
                
                $result['failed_items'][] = [
                    'id' => $item['id'],
                    'operation' => $item['operation_type'],
                    'status' => 'critical_error',
                    'message' => $e->getMessage()
                ];
                
                error_log("Queue item {$item['id']} critical error: " . $e->getMessage());
            }
        }
        
        $processed_count = count($result['processed_items']);
        $failed_count = count($result['failed_items']);
        
        $result['message'] = "Kuyruk işlendi. Başarılı: $processed_count, Başarısız: $failed_count";
        $result['queue_stats'] = getQueueStatistics();
        
        // İstatistikleri güncelle
        updateDailySyncStats($processed_count, $failed_count);
        
    } catch (Exception $e) {
        throw new Exception('processQueue error: ' . $e->getMessage());
    }
    
    return $result;
}

/**
 * Kuyruk öğesini işle
 */
function processQueueItem($item) {
    $operation_type = $item['operation_type'];
    $data = json_decode($item['data_json'], true);
    
    switch ($operation_type) {
        case 'sale':
            return processSaleQueueItem($item, $data);
            
        case 'stock_update':
            return processStockUpdateQueueItem($item, $data);
            
        case 'customer_update':
            return processCustomerUpdateQueueItem($item, $data);
            
        case 'price_update':
            return processPriceUpdateQueueItem($item, $data);
            
        default:
            return [
                'success' => false,
                'message' => "Desteklenmeyen operasyon tipi: $operation_type"
            ];
    }
}

/**
 * Satış kuyruk öğesini işle
 */
function processSaleQueueItem($item, $data) {
    global $MAIN_SERVER_URL, $API_KEY, $STORE_ID;
    
    try {
        // Satış verilerini ana sunucuya gönder
        $sale_data = [
            'store_id' => $STORE_ID,
            'local_sale_id' => $data['sale_id'],
            'sale_data' => $data['sale_data'],
            'customer_data' => $data['customer_data'] ?? null,
            'items_data' => $data['items_data'] ?? [],
            'metadata' => [
                'sync_timestamp' => date('Y-m-d H:i:s'),
                'source' => 'merkez_queue_retry',
                'queue_item_id' => $item['id'],
                'attempt' => $item['attempts'] + 1
            ]
        ];
        
        // HMAC imza oluştur
        $timestamp = time();
        $message = $STORE_ID . $data['sale_id'] . $timestamp;
        $signature = hash_hmac('sha256', $message, $API_KEY);
        
        // HTTP başlıkları
        $headers = [
            'Content-Type: application/json',
            'User-Agent: Merkez-Queue-v2.1',
            'X-Store-ID: ' . $STORE_ID,
            'X-Timestamp: ' . $timestamp,
            'X-Signature: ' . $signature,
            'X-API-Key: ' . $API_KEY
        ];
        
        // cURL ile gönder
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $MAIN_SERVER_URL,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($sale_data),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($curl_error) {
            return [
                'success' => false,
                'message' => 'cURL Error: ' . $curl_error
            ];
        }
        
        if ($http_code !== 200) {
            return [
                'success' => false,
                'message' => "HTTP Error: $http_code - Response: $response"
            ];
        }
        
        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'message' => 'Invalid JSON response: ' . $response
            ];
        }
        
        if ($result['success']) {
            // Satış başarıyla gönderildi, local sync durumunu güncelle
            updateLocalSaleSync($data['sale_id'], 1, 'Queue retry successful');
        }
        
        return $result;
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Sale processing error: ' . $e->getMessage()
        ];
    }
}

/**
 * Stok güncelleme kuyruk öğesini işle
 */
function processStockUpdateQueueItem($item, $data) {
    global $conn;
    
    try {
        // Local stok güncellemesini uygula
        if ($data['operation'] === 'stock_adjustment') {
            $stmt = $conn->prepare("
                UPDATE magaza_stok 
                SET stok_miktari = :new_amount,
                    son_guncelleme = NOW()
                WHERE barkod = :barkod AND magaza_id = :magaza_id
            ");
            
            $stmt->execute([
                ':new_amount' => $data['new_amount'],
                ':barkod' => $data['barkod'],
                ':magaza_id' => $data['magaza_id']
            ]);
            
            return [
                'success' => true,
                'message' => 'Stok güncellemesi başarılı'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Bilinmeyen stok operasyonu'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Stock update error: ' . $e->getMessage()
        ];
    }
}

/**
 * Müşteri güncelleme kuyruk öğesini işle
 */
function processCustomerUpdateQueueItem($item, $data) {
    global $conn;
    
    try {
        // Müşteri bilgilerini güncelle
        $stmt = $conn->prepare("
            UPDATE musteriler 
            SET ad = :ad, soyad = :soyad, telefon = :telefon,
                email = :email, last_updated = NOW()
            WHERE id = :id
        ");
        
        $stmt->execute([
            ':ad' => $data['ad'],
            ':soyad' => $data['soyad'],
            ':telefon' => $data['telefon'],
            ':email' => $data['email'],
            ':id' => $data['customer_id']
        ]);
        
        return [
            'success' => true,
            'message' => 'Müşteri güncellemesi başarılı'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Customer update error: ' . $e->getMessage()
        ];
    }
}

/**
 * Fiyat güncelleme kuyruk öğesini işle
 */
function processPriceUpdateQueueItem($item, $data) {
    global $conn;
    
    try {
        // Ürün fiyatını güncelle
        $stmt = $conn->prepare("
            UPDATE urun_stok 
            SET satis_fiyati = :new_price,
                indirimli_fiyat = :discounted_price,
                last_updated = NOW()
            WHERE barkod = :barkod
        ");
        
        $stmt->execute([
            ':new_price' => $data['satis_fiyati'],
            ':discounted_price' => $data['indirimli_fiyat'],
            ':barkod' => $data['barkod']
        ]);
        
        // Mağaza stoklarındaki fiyatı da güncelle
        $stmt2 = $conn->prepare("
            UPDATE magaza_stok 
            SET satis_fiyati = :new_price,
                son_guncelleme = NOW()
            WHERE barkod = :barkod
        ");
        
        $stmt2->execute([
            ':new_price' => $data['satis_fiyati'],
            ':barkod' => $data['barkod']
        ]);
        
        return [
            'success' => true,
            'message' => 'Fiyat güncellemesi başarılı'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Price update error: ' . $e->getMessage()
        ];
    }
}

/**
 * Başarısız öğeleri tekrar dene
 */
function retryFailedItems() {
    global $conn, $STORE_ID, $MAX_RETRY_ATTEMPTS;
    
    $result = [
        'message' => '',
        'retry_count' => 0,
        'failed_count' => 0
    ];
    
    try {
        // Tekrar denenebilir başarısız öğeleri getir
        $stmt = $conn->prepare("
            SELECT * FROM sync_queue 
            WHERE magaza_id = :magaza_id 
                AND status = 'failed'
                AND attempts < :max_attempts
            ORDER BY priority ASC, created_at ASC
            LIMIT 10
        ");
        
        $stmt->execute([
            ':magaza_id' => $STORE_ID,
            ':max_attempts' => $MAX_RETRY_ATTEMPTS
        ]);
        
        $failed_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($failed_items as $item) {
            // Durumu pending'e çevir ve scheduled_at'ı şimdi yap
            $stmt2 = $conn->prepare("
                UPDATE sync_queue 
                SET status = 'pending',
                    scheduled_at = NOW(),
                    error_message = CONCAT(IFNULL(error_message, ''), ' | Manual retry at: ', NOW())
                WHERE id = :id
            ");
            
            if ($stmt2->execute([':id' => $item['id']])) {
                $result['retry_count']++;
            } else {
                $result['failed_count']++;
            }
        }
        
        $result['message'] = "Manuel tekrar deneme tamamlandı. Başarılı: {$result['retry_count']}, Başarısız: {$result['failed_count']}";
        
    } catch (Exception $e) {
        throw new Exception('retryFailedItems error: ' . $e->getMessage());
    }
    
    return $result;
}

/**
 * Kuyruk durumunu getir
 */
function getQueueStatus() {
    global $conn, $STORE_ID;
    
    try {
        $stmt = $conn->prepare("
            SELECT 
                status,
                operation_type,
                COUNT(*) as count,
                MIN(created_at) as oldest,
                MAX(created_at) as newest
            FROM sync_queue 
            WHERE magaza_id = :magaza_id
                AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY status, operation_type
            ORDER BY status, operation_type
        ");
        
        $stmt->execute([':magaza_id' => $STORE_ID]);
        $queue_status = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Özet istatistikler
        $stmt2 = $conn->prepare("
            SELECT 
                COUNT(*) as total_items,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                AVG(attempts) as avg_attempts
            FROM sync_queue 
            WHERE magaza_id = :magaza_id
                AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        
        $stmt2->execute([':magaza_id' => $STORE_ID]);
        $summary = $stmt2->fetch(PDO::FETCH_ASSOC);
        
        return [
            'message' => 'Kuyruk durumu alındı',
            'queue_status' => $queue_status,
            'summary' => $summary,
            'queue_stats' => getQueueStatistics()
        ];
        
    } catch (Exception $e) {
        throw new Exception('getQueueStatus error: ' . $e->getMessage());
    }
}

/**
 * Eski kayıtları temizle
 */
function cleanOldRecords() {
    global $conn, $STORE_ID;
    
    $result = [
        'message' => '',
        'deleted_count' => 0
    ];
    
    try {
        // 30 günden eski tamamlanmış kayıtları sil
        $stmt = $conn->prepare("
            DELETE FROM sync_queue 
            WHERE magaza_id = :magaza_id 
                AND status = 'completed'
                AND processed_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        
        $stmt->execute([':magaza_id' => $STORE_ID]);
        $deleted_completed = $stmt->rowCount();
        
        // 7 günden eski iptal edilmiş kayıtları sil
        $stmt2 = $conn->prepare("
            DELETE FROM sync_queue 
            WHERE magaza_id = :magaza_id 
                AND status = 'cancelled'
                AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        
        $stmt2->execute([':magaza_id' => $STORE_ID]);
        $deleted_cancelled = $stmt2->rowCount();
        
        $result['deleted_count'] = $deleted_completed + $deleted_cancelled;
        $result['message'] = "Temizlik tamamlandı. Silinen kayıt: {$result['deleted_count']} (Tamamlanmış: $deleted_completed, İptal: $deleted_cancelled)";
        
    } catch (Exception $e) {
        throw new Exception('cleanOldRecords error: ' . $e->getMessage());
    }
    
    return $result;
}

/**
 * Öğe önceliğini ayarla
 */
function prioritizeItem($item_id, $priority) {
    global $conn;
    
    try {
        if (!$item_id || !is_numeric($priority)) {
            throw new Exception('Geçersiz item_id veya priority değeri');
        }
        
        $stmt = $conn->prepare("
            UPDATE sync_queue 
            SET priority = :priority 
            WHERE id = :item_id
        ");
        
        $stmt->execute([
            ':priority' => (int)$priority,
            ':item_id' => (int)$item_id
        ]);
        
        return [
            'message' => "Öğe #{$item_id} önceliği {$priority} olarak güncellendi",
            'updated_count' => $stmt->rowCount()
        ];
        
    } catch (Exception $e) {
        throw new Exception('prioritizeItem error: ' . $e->getMessage());
    }
}

/**
 * Öğeyi iptal et
 */
function cancelItem($item_id) {
    global $conn;
    
    try {
        if (!$item_id) {
            throw new Exception('Geçersiz item_id');
        }
        
        $stmt = $conn->prepare("
            UPDATE sync_queue 
            SET status = 'cancelled',
                processed_at = NOW(),
                error_message = CONCAT(IFNULL(error_message, ''), ' | Cancelled by user at: ', NOW())
            WHERE id = :item_id
                AND status IN ('pending', 'failed')
        ");
        
        $stmt->execute([':item_id' => (int)$item_id]);
        
        return [
            'message' => "Öğe #{$item_id} iptal edildi",
            'cancelled_count' => $stmt->rowCount()
        ];
        
    } catch (Exception $e) {
        throw new Exception('cancelItem error: ' . $e->getMessage());
    }
}

/**
 * Tekrar deneme zamanlamasını getir
 */
function getRetrySchedule() {
    global $conn, $STORE_ID, $RETRY_DELAY_MINUTES;
    
    try {
        $stmt = $conn->prepare("
            SELECT 
                id, operation_type, attempts, scheduled_at,
                error_message, created_at
            FROM sync_queue 
            WHERE magaza_id = :magaza_id 
                AND status = 'failed'
                AND scheduled_at > NOW()
            ORDER BY scheduled_at ASC
        ");
        
        $stmt->execute([':magaza_id' => $STORE_ID]);
        $scheduled_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'message' => 'Tekrar deneme zamanlaması alındı',
            'scheduled_items' => $scheduled_items,
            'retry_delays' => $RETRY_DELAY_MINUTES
        ];
        
    } catch (Exception $e) {
        throw new Exception('getRetrySchedule error: ' . $e->getMessage());
    }
}

/**
 * Yardımcı Fonksiyonlar
 */

function updateQueueItemStatus($item_id, $status, $error_message = null, $success_message = null) {
    global $conn;
    
    try {
        if ($status === 'processing') {
            $stmt = $conn->prepare("
                UPDATE sync_queue 
                SET status = :status,
                    attempts = attempts + 1
                WHERE id = :item_id
            ");
            
            $stmt->execute([
                ':status' => $status,
                ':item_id' => $item_id
            ]);
        } else {
            $message = $error_message ?: $success_message;
            
            $stmt = $conn->prepare("
                UPDATE sync_queue 
                SET status = :status,
                    processed_at = NOW(),
                    error_message = :message
                WHERE id = :item_id
            ");
            
            $stmt->execute([
                ':status' => $status,
                ':message' => $message,
                ':item_id' => $item_id
            ]);
        }
        
    } catch (Exception $e) {
        error_log("updateQueueItemStatus error: " . $e->getMessage());
    }
}

function scheduleRetry($item_id, $attempt, $error_message) {
    global $conn, $RETRY_DELAY_MINUTES;
    
    try {
        $delay_index = min($attempt - 1, count($RETRY_DELAY_MINUTES) - 1);
        $delay_minutes = $RETRY_DELAY_MINUTES[$delay_index];
        
        $stmt = $conn->prepare("
            UPDATE sync_queue 
            SET status = 'failed',
                scheduled_at = DATE_ADD(NOW(), INTERVAL :delay_minutes MINUTE),
                error_message = :error_message
            WHERE id = :item_id
        ");
        
        $stmt->execute([
            ':delay_minutes' => $delay_minutes,
            ':error_message' => $error_message . " (Attempt $attempt, retry in $delay_minutes minutes)",
            ':item_id' => $item_id
        ]);
        
    } catch (Exception $e) {
        error_log("scheduleRetry error: " . $e->getMessage());
    }
}

function updateLocalSaleSync($sale_id, $status, $message) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            UPDATE satis_faturalari 
            SET sync_durumu = :status,
                sync_tarihi = NOW(),
                aciklama = CONCAT(IFNULL(aciklama, ''), ' | ', :message)
            WHERE id = :sale_id
        ");
        
        $stmt->execute([
            ':status' => $status,
            ':message' => $message,
            ':sale_id' => $sale_id
        ]);
        
    } catch (Exception $e) {
        error_log("updateLocalSaleSync error: " . $e->getMessage());
    }
}

function getQueueStatistics() {
    global $conn, $STORE_ID;
    
    try {
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_items,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                AVG(attempts) as avg_attempts
            FROM sync_queue 
            WHERE magaza_id = :magaza_id
                AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        
        $stmt->execute([':magaza_id' => $STORE_ID]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function updateDailySyncStats($successful_count, $failed_count) {
    global $conn, $STORE_ID;
    
    try {
        $today = date('Y-m-d');
        $total_ops = $successful_count + $failed_count;
        
        $stmt = $conn->prepare("
            INSERT INTO sync_stats (
                magaza_id, stat_date, total_operations,
                successful_operations, failed_operations,
                last_sync_time, created_at, updated_at
            ) VALUES (
                :magaza_id, :stat_date, :total_ops,
                :successful_ops, :failed_ops,
                NOW(), NOW(), NOW()
            ) ON DUPLICATE KEY UPDATE
                total_operations = total_operations + :total_ops,
                successful_operations = successful_operations + :successful_ops,
                failed_operations = failed_operations + :failed_ops,
                last_sync_time = NOW(),
                updated_at = NOW()
        ");
        
        $stmt->execute([
            ':magaza_id' => $STORE_ID,
            ':stat_date' => $today,
            ':total_ops' => $total_ops,
            ':successful_ops' => $successful_count,
            ':failed_ops' => $failed_count
        ]);
        
    } catch (Exception $e) {
        error_log("updateDailySyncStats error: " . $e->getMessage());
    }
}