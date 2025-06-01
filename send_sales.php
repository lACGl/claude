<?php
/**
 * Mağaza Satış Gönderim API'si
 * Satışları ana sunucuya gönderir (online/offline destekli)
 * 
 * Özellikler:
 * - Anında gönderim (online modda)
 * - Kuyruk yönetimi (offline modda) 
 * - Batch gönderim
 * - Hata yönetimi ve retry
 * - Çakışma tespiti
 */

require_once '../../session_manager.php';
secure_session_start();
require_once '../../db_connection.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Yetki kontrolü
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    die(json_encode([
        'success' => false, 
        'message' => 'Yetkisiz erişim'
    ]));
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method === 'POST') {
        // Satış gönderimi
        handleSaleSending();
    } elseif ($method === 'GET') {
        // Kuyruk durumu sorgusu
        handleQueueStatus();
    } else {
        throw new Exception('Desteklenmeyen HTTP metodu');
    }

} catch (Exception $e) {
    error_log('Send Sales API Hatası: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Satış gönderim işlemi
 */
function handleSaleSending() {
    global $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Geçersiz JSON verisi');
    }
    
    // Parametreler
    $mode = $input['mode'] ?? 'single'; // 'single' veya 'batch'
    $force_send = $input['force_send'] ?? false;
    
    if ($mode === 'single') {
        // Tek satış gönderimi
        $sale_data = $input['sale_data'] ?? null;
        if (!$sale_data) {
            throw new Exception('Satış verisi eksik');
        }
        
        $result = sendSingleSale($sale_data, $force_send);
        
    } elseif ($mode === 'batch') {
        // Toplu gönderim (offline kuyruktan)
        $limit = $input['limit'] ?? 10;
        $result = sendBatchSales($limit);
        
    } else {
        throw new Exception('Geçersiz gönderim modu');
    }
    
    echo json_encode($result);
}

/**
 * Kuyruk durumu sorgusu
 */
function handleQueueStatus() {
    global $conn;
    
    try {
        // Bekleyen satışları say
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as pending_count,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_count,
                COUNT(CASE WHEN status = 'processing' THEN 1 END) as processing_count,
                MIN(created_at) as oldest_pending
            FROM sync_queue 
            WHERE operation_type = 'sale' 
            AND status IN ('pending', 'failed', 'processing')
        ");
        $stmt->execute();
        $queue_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Offline satışları da kontrol et
        $stmt = $conn->prepare("
            SELECT COUNT(*) as offline_count 
            FROM offline_sales 
            WHERE status = 'pending'
        ");
        $stmt->execute();
        $offline_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'queue_status' => [
                'pending_sales' => $queue_stats['pending_count'],
                'failed_sales' => $queue_stats['failed_count'],
                'processing_sales' => $queue_stats['processing_count'],
                'offline_sales' => $offline_stats['offline_count'],
                'oldest_pending' => $queue_stats['oldest_pending'],
                'last_check' => date('Y-m-d H:i:s')
            ]
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Kuyruk durumu alınamadı: ' . $e->getMessage());
    }
}

/**
 * Tek satış gönderimi
 */
function sendSingleSale($sale_data, $force_send = false) {
    global $conn;
    
    try {
        // Satış verisi doğrulama
        validateSaleData($sale_data);
        
        // Ana sunucu erişilebilir mi kontrol et
        if (!$force_send && !isServerReachable()) {
            // Offline modda kuyruga ekle
            $queue_id = addToQueue($sale_data);
            
            return [
                'success' => true,
                'mode' => 'offline',
                'message' => 'Satış offline kuyruğa eklendi',
                'queue_id' => $queue_id,
                'will_sync' => true
            ];
        }
        
        // Online gönderim dene
        $response = sendToMainServer($sale_data);
        
        if ($response['success']) {
            // Başarılı - eğer kuyrukta bekliyorsa kaldır
            removeFromQueueBySaleId($sale_data['local_sale_id'] ?? null);
            
            return [
                'success' => true,
                'mode' => 'online',
                'message' => 'Satış başarıyla gönderildi',
                'server_response' => $response,
                'invoice_id' => $response['invoiceId'] ?? null
            ];
        } else {
            // Başarısız - kuyruga ekle
            $queue_id = addToQueue($sale_data, $response['message'] ?? 'Bilinmeyen hata');
            
            return [
                'success' => false,
                'mode' => 'queued',
                'message' => 'Gönderim başarısız, kuyruga eklendi: ' . ($response['message'] ?? ''),
                'queue_id' => $queue_id,
                'will_retry' => true
            ];
        }
        
    } catch (Exception $e) {
        // Kritik hata - kuyruga ekle
        $queue_id = addToQueue($sale_data, 'Kritik hata: ' . $e->getMessage());
        
        return [
            'success' => false,
            'mode' => 'error',
            'message' => 'Hata oluştu, kuyruga eklendi: ' . $e->getMessage(),
            'queue_id' => $queue_id
        ];
    }
}

/**
 * Toplu satış gönderimi (kuyruktan)
 */
function sendBatchSales($limit = 10) {
    global $conn;
    
    try {
        $conn->beginTransaction();
        
        // Bekleyen satışları al (öncelik sırasına göre)
        $stmt = $conn->prepare("
            SELECT * FROM sync_queue 
            WHERE operation_type = 'sale' 
            AND status IN ('pending', 'failed')
            AND scheduled_at <= NOW()
            AND attempts < max_attempts
            ORDER BY priority DESC, created_at ASC
            LIMIT :limit
            FOR UPDATE
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $pending_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $results = [
            'success' => true,
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'details' => []
        ];
        
        if (empty($pending_sales)) {
            $conn->commit();
            $results['message'] = 'Gönderilecek satış bulunamadı';
            return $results;
        }
        
        // Ana sunucu erişilebilir mi kontrol et
        if (!isServerReachable()) {
            $conn->commit();
            $results['success'] = false;
            $results['message'] = 'Ana sunucu erişilemiyor';
            return $results;
        }
        
        foreach ($pending_sales as $sale) {
            $results['processed']++;
            
            try {
                // Status'u processing yap
                updateQueueStatus($sale['id'], 'processing');
                
                // Satış verisini decode et
                $sale_data = json_decode($sale['data_json'], true);
                
                // Ana sunucuya gönder
                $response = sendToMainServer($sale_data);
                
                if ($response['success']) {
                    // Başarılı - kuyruktan kaldır
                    removeFromQueue($sale['id']);
                    $results['successful']++;
                    
                    $results['details'][] = [
                        'queue_id' => $sale['id'],
                        'status' => 'success',
                        'invoice_id' => $response['invoiceId'] ?? null
                    ];
                    
                } else {
                    // Başarısız - attempt sayısını artır
                    incrementQueueAttempt($sale['id'], $response['message'] ?? 'Bilinmeyen hata');
                    $results['failed']++;
                    
                    $results['details'][] = [
                        'queue_id' => $sale['id'],
                        'status' => 'failed',
                        'error' => $response['message'] ?? 'Bilinmeyen hata',
                        'attempts' => $sale['attempts'] + 1
                    ];
                }
                
            } catch (Exception $e) {
                // Satış işlemi hatası
                incrementQueueAttempt($sale['id'], 'Exception: ' . $e->getMessage());
                $results['failed']++;
                
                $results['details'][] = [
                    'queue_id' => $sale['id'],
                    'status' => 'error',
                    'error' => $e->getMessage(),
                    'attempts' => $sale['attempts'] + 1
                ];
            }
        }
        
        $conn->commit();
        
        $results['message'] = "Toplu gönderim tamamlandı. {$results['successful']}/{$results['processed']} başarılı";
        
        return $results;
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw new Exception('Toplu gönderim hatası: ' . $e->getMessage());
    }
}

/**
 * Ana sunucuya satış gönder
 */
function sendToMainServer($sale_data) {
    // Ana sunucu URL'ini sistem ayarlarından al
    $main_server_url = getMainServerUrl() . '/api/sync/receive_sales.php';
    
    // API key'i al
    $api_key = getApiKey();
    
    // Payload hazırla
    $payload = json_encode([
        'sale' => $sale_data,
        'sync_metadata' => [
            'source_store' => getCurrentStoreId(),
            'device_id' => getDeviceId(),
            'timestamp' => time(),
            'version' => '1.0'
        ]
    ]);
    
    // HMAC imza oluştur
    $signature = hash_hmac('sha256', $payload, getWebhookSecret());
    
    // cURL isteği
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $main_server_url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-API-Key: ' . $api_key,
            'X-Webhook-Signature: ' . $signature,
            'User-Agent: POS-Store-Sync/1.0'
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($curl_error) {
        throw new Exception('Network hatası: ' . $curl_error);
    }
    
    if ($http_code !== 200) {
        throw new Exception('HTTP Error: ' . $http_code);
    }
    
    $decoded_response = json_decode($response, true);
    
    if (!$decoded_response) {
        throw new Exception('Geçersiz sunucu yanıtı');
    }
    
    return $decoded_response;
}

/**
 * Satış verisini doğrula
 */
function validateSaleData($sale_data) {
    $required_fields = ['fisNo', 'magazaId', 'kasiyerId', 'sepet', 'genelToplam'];
    
    foreach ($required_fields as $field) {
        if (!isset($sale_data[$field])) {
            throw new Exception("Eksik alan: {$field}");
        }
    }
    
    if (empty($sale_data['sepet'])) {
        throw new Exception('Sepet boş olamaz');
    }
    
    if (!is_numeric($sale_data['genelToplam']) || $sale_data['genelToplam'] <= 0) {
        throw new Exception('Geçersiz toplam tutar');
    }
    
    // Fiş numarası format kontrolü
    if (!preg_match('/^\d{7}-\d{4}-\d{4}$/', $sale_data['fisNo'])) {
        throw new Exception('Geçersiz fiş numarası formatı');
    }
}

/**
 * Ana sunucu erişilebilirlik kontrolü
 */
function isServerReachable() {
    try {
        $main_server_url = getMainServerUrl();
        
        // Ping endpoint'i kontrol et
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $main_server_url . '/api/sync/ping.php',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_NOBODY => true, // HEAD request
            CURLOPT_SSL_VERIFYPEER => false // Geliştirme için
        ]);
        
        curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $http_code === 200;
        
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Kuyruga satış ekle
 */
function addToQueue($sale_data, $error_message = null) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            INSERT INTO sync_queue (
                magaza_id, operation_type, table_name, data_json, 
                priority, attempts, max_attempts, status, error_message,
                created_at, scheduled_at, sync_hash
            ) VALUES (
                :magaza_id, 'sale', 'satis_faturalari', :data_json,
                :priority, 0, 3, :status, :error_message,
                NOW(), NOW(), :sync_hash
            )
        ");
        
        $magaza_id = getCurrentStoreId();
        $data_json = json_encode($sale_data);
        $priority = $error_message ? 3 : 5; // Hatalı olanlar düşük öncelik
        $status = $error_message ? 'failed' : 'pending';
        $sync_hash = md5($data_json . time());
        
        $stmt->bindParam(':magaza_id', $magaza_id);
        $stmt->bindParam(':data_json', $data_json);
        $stmt->bindParam(':priority', $priority);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':error_message', $error_message);
        $stmt->bindParam(':sync_hash', $sync_hash);
        
        $stmt->execute();
        
        return $conn->lastInsertId();
        
    } catch (Exception $e) {
        throw new Exception('Kuyruga ekleme hatası: ' . $e->getMessage());
    }
}

/**
 * Kuyruktan satış kaldır
 */
function removeFromQueue($queue_id) {
    global $conn;
    
    $stmt = $conn->prepare("DELETE FROM sync_queue WHERE id = :id");
    $stmt->bindParam(':id', $queue_id);
    return $stmt->execute();
}

/**
 * Satış ID'sine göre kuyruktan kaldır
 */
function removeFromQueueBySaleId($sale_id) {
    global $conn;
    
    if (!$sale_id) return false;
    
    $stmt = $conn->prepare("
        DELETE FROM sync_queue 
        WHERE operation_type = 'sale' 
        AND JSON_EXTRACT(data_json, '$.fisNo') = :sale_id
    ");
    $stmt->bindParam(':sale_id', $sale_id);
    return $stmt->execute();
}

/**
 * Kuyruk durumunu güncelle
 */
function updateQueueStatus($queue_id, $status) {
    global $conn;
    
    $stmt = $conn->prepare("
        UPDATE sync_queue 
        SET status = :status, processed_at = NOW() 
        WHERE id = :id
    ");
    $stmt->bindParam(':status', $status);
    $stmt->bindParam(':id', $queue_id);
    return $stmt->execute();
}

/**
 * Kuyruk deneme sayısını artır
 */
function incrementQueueAttempt($queue_id, $error_message) {
    global $conn;
    
    $stmt = $conn->prepare("
        UPDATE sync_queue 
        SET 
            attempts = attempts + 1,
            error_message = :error_message,
            status = CASE 
                WHEN attempts + 1 >= max_attempts THEN 'failed'
                ELSE 'pending'
            END,
            scheduled_at = DATE_ADD(NOW(), INTERVAL POWER(2, attempts + 1) MINUTE)
        WHERE id = :id
    ");
    $stmt->bindParam(':error_message', $error_message);
    $stmt->bindParam(':id', $queue_id);
    return $stmt->execute();
}

/**
 * Sistem ayarlarından ana sunucu URL'ini al
 */
function getMainServerUrl() {
    global $conn;
    
    $stmt = $conn->prepare("SELECT deger FROM sistem_ayarlari WHERE anahtar = 'main_server_url'");
    $stmt->execute();
    $result = $stmt->fetchColumn();
    
    return $result ?: 'https://pos.incikirtasiye.com'; // Varsayılan
}

/**
 * API key al
 */
function getApiKey() {
    global $conn;
    
    $stmt = $conn->prepare("SELECT deger FROM sistem_ayarlari WHERE anahtar = 'api_key'");
    $stmt->execute();
    $result = $stmt->fetchColumn();
    
    return $result ?: 'default_api_key_2024'; // Varsayılan
}

/**
 * Webhook secret al
 */
function getWebhookSecret() {
    global $conn;
    
    $stmt = $conn->prepare("SELECT deger FROM sistem_ayarlari WHERE anahtar = 'webhook_secret'");
    $stmt->execute();
    $result = $stmt->fetchColumn();
    
    return $result ?: 'pos_webhook_secret_2024'; // Varsayılan
}

/**
 * Mevcut mağaza ID'sini al
 */
function getCurrentStoreId() {
    global $conn;
    
    // Session'dan al
    if (isset($_SESSION['magaza_id'])) {
        return $_SESSION['magaza_id'];
    }
    
    // Sistem ayarlarından al
    $stmt = $conn->prepare("SELECT deger FROM sistem_ayarlari WHERE anahtar = 'magaza_id'");
    $stmt->execute();
    $result = $stmt->fetchColumn();
    
    return $result ?: 1; // Varsayılan
}

/**
 * Device ID al/oluştur
 */
function getDeviceId() {
    global $conn;
    
    $stmt = $conn->prepare("SELECT deger FROM sistem_ayarlari WHERE anahtar = 'device_id'");
    $stmt->execute();
    $device_id = $stmt->fetchColumn();
    
    if (!$device_id) {
        // Yeni device ID oluştur
        $device_id = 'STORE_' . getCurrentStoreId() . '_' . uniqid();
        
        $stmt = $conn->prepare("
            INSERT INTO sistem_ayarlari (anahtar, deger, aciklama) 
            VALUES ('device_id', :device_id, 'Otomatik oluşturulmuş cihaz ID')
            ON DUPLICATE KEY UPDATE deger = :device_id
        ");
        $stmt->bindParam(':device_id', $device_id);
        $stmt->execute();
    }
    
    return $device_id;
}
?>