<?php
/**
 * Mağaza Güncelleme Alma API'si
 * Ana sunucudan polling yöntemi ile güncellemeleri alır
 * 
 * Özellikler:
 * - Müşteri bilgileri sync (merkezi)
 * - Müşteri puanları sync (merkezi) 
 * - Ürün bilgileri güncelleme
 * - Sistem ayarları sync
 * - Incremental sync (son güncelleme zamanından itibaren)
 * - Çakışma tespiti ve yönetimi
 */

require_once '../../session_manager.php';
secure_session_start();
require_once '../../db_connection.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
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
    
    if ($method === 'GET') {
        // Polling ile güncelleme al
        handleUpdatePolling();
    } elseif ($method === 'POST') {
        // Belirli güncelleme talep et
        handleSpecificUpdate();
    } else {
        throw new Exception('Desteklenmeyen HTTP metodu');
    }

} catch (Exception $e) {
    error_log('Receive Updates API Hatası: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Polling ile güncelleme alma
 */
function handleUpdatePolling() {
    global $conn;
    
    try {
        // URL parametrelerini al
        $since_timestamp = $_GET['since'] ?? null;
        $update_types = explode(',', $_GET['types'] ?? 'customers,products,settings');
        $limit = min(intval($_GET['limit'] ?? 100), 500); // Max 500
        
        // Mağaza ID'sini al
        $store_id = getCurrentStoreId();
        
        // Ana sunucu erişilebilir mi kontrol et
        if (!isMainServerReachable()) {
            throw new Exception('Ana sunucu erişilemiyor');
        }
        
        // Son sync zamanını belirle
        if (!$since_timestamp) {
            $since_timestamp = getLastSyncTimestamp($store_id);
        }
        
        $updates = [];
        $total_updates = 0;
        
        // Her güncelleme türü için kontrol et
        foreach ($update_types as $type) {
            $type = trim($type);
            
            switch ($type) {
                case 'customers':
                    $customer_updates = fetchCustomerUpdates($since_timestamp, $limit);
                    if (!empty($customer_updates)) {
                        $updates['customers'] = $customer_updates;
                        $total_updates += count($customer_updates);
                    }
                    break;
                    
                case 'products':
                    $product_updates = fetchProductUpdates($since_timestamp, $limit);
                    if (!empty($product_updates)) {
                        $updates['products'] = $product_updates;
                        $total_updates += count($product_updates);
                    }
                    break;
                    
                case 'settings':
                    $settings_updates = fetchSettingsUpdates($since_timestamp, $limit);
                    if (!empty($settings_updates)) {
                        $updates['settings'] = $settings_updates;
                        $total_updates += count($settings_updates);
                    }
                    break;
                    
                case 'points':
                    $points_updates = fetchPointsUpdates($since_timestamp, $limit);
                    if (!empty($points_updates)) {
                        $updates['points'] = $points_updates;
                        $total_updates += count($points_updates);
                    }
                    break;
            }
        }
        
        // Güncellemeleri uygula
        if ($total_updates > 0) {
            $applied_updates = applyUpdates($updates);
            
            // Son sync zamanını güncelle
            updateLastSyncTimestamp($store_id, time());
            
            echo json_encode([
                'success' => true,
                'total_updates' => $total_updates,
                'applied_updates' => $applied_updates,
                'updates' => $updates,
                'last_sync' => date('Y-m-d H:i:s'),
                'message' => "{$applied_updates}/{$total_updates} güncelleme uygulandı"
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'total_updates' => 0,
                'message' => 'Yeni güncelleme bulunamadı',
                'last_check' => date('Y-m-d H:i:s')
            ]);
        }
        
    } catch (Exception $e) {
        throw new Exception('Polling hatası: ' . $e->getMessage());
    }
}

/**
 * Belirli güncelleme talep etme
 */
function handleSpecificUpdate() {
    global $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Geçersiz JSON verisi');
    }
    
    $update_type = $input['type'] ?? null;
    $entity_id = $input['entity_id'] ?? null;
    $force_update = $input['force'] ?? false;
    
    if (!$update_type) {
        throw new Exception('Güncelleme türü belirtilmedi');
    }
    
    try {
        $result = [];
        
        switch ($update_type) {
            case 'customer':
                if (!$entity_id) {
                    throw new Exception('Müşteri ID gerekli');
                }
                $result = fetchSpecificCustomer($entity_id, $force_update);
                break;
                
            case 'product':
                if (!$entity_id) {
                    throw new Exception('Ürün ID gerekli');
                }
                $result = fetchSpecificProduct($entity_id, $force_update);
                break;
                
            case 'full_sync':
                $result = performFullSync($force_update);
                break;
                
            default:
                throw new Exception('Geçersiz güncelleme türü: ' . $update_type);
        }
        
        echo json_encode([
            'success' => true,
            'update_type' => $update_type,
            'result' => $result,
            'timestamp' => time()
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Özel güncelleme hatası: ' . $e->getMessage());
    }
}

/**
 * Ana sunucudan müşteri güncellemelerini al
 */
function fetchCustomerUpdates($since_timestamp, $limit) {
    try {
        $response = callMainServerAPI('/api/sync/distribute_data.php', [
            'type' => 'customers',
            'store_id' => getCurrentStoreId(),
            'since' => $since_timestamp,
            'limit' => $limit
        ]);
        
        if ($response['success'] && !empty($response['data'])) {
            return $response['data'];
        }
        
        return [];
        
    } catch (Exception $e) {
        error_log('Müşteri güncelleme hatası: ' . $e->getMessage());
        return [];
    }
}

/**
 * Ana sunucudan ürün güncellemelerini al
 */
function fetchProductUpdates($since_timestamp, $limit) {
    try {
        $response = callMainServerAPI('/api/sync/distribute_data.php', [
            'type' => 'products',
            'store_id' => getCurrentStoreId(),
            'since' => $since_timestamp,
            'limit' => $limit
        ]);
        
        if ($response['success'] && !empty($response['data'])) {
            return $response['data'];
        }
        
        return [];
        
    } catch (Exception $e) {
        error_log('Ürün güncelleme hatası: ' . $e->getMessage());
        return [];
    }
}

/**
 * Ana sunucudan puan güncellemelerini al
 */
function fetchPointsUpdates($since_timestamp, $limit) {
    try {
        $response = callMainServerAPI('/api/sync/distribute_data.php', [
            'type' => 'points',
            'store_id' => getCurrentStoreId(),
            'since' => $since_timestamp,
            'limit' => $limit
        ]);
        
        if ($response['success'] && !empty($response['data'])) {
            return $response['data'];
        }
        
        return [];
        
    } catch (Exception $e) {
        error_log('Puan güncelleme hatası: ' . $e->getMessage());
        return [];
    }
}

/**
 * Ana sunucudan sistem ayarları güncellemelerini al
 */
function fetchSettingsUpdates($since_timestamp, $limit) {
    try {
        $response = callMainServerAPI('/api/sync/distribute_data.php', [
            'type' => 'settings',
            'store_id' => getCurrentStoreId(),
            'since' => $since_timestamp,
            'limit' => $limit
        ]);
        
        if ($response['success'] && !empty($response['data'])) {
            return $response['data'];
        }
        
        return [];
        
    } catch (Exception $e) {
        error_log('Ayarlar güncelleme hatası: ' . $e->getMessage());
        return [];
    }
}

/**
 * Güncellemeleri local veritabanına uygula
 */
function applyUpdates($updates) {
    global $conn;
    
    $applied_count = 0;
    
    try {
        $conn->beginTransaction();
        
        // Müşteri güncellemeleri
        if (isset($updates['customers'])) {
            foreach ($updates['customers'] as $customer) {
                if (applyCustomerUpdate($customer)) {
                    $applied_count++;
                }
            }
        }
        
        // Ürün güncellemeleri
        if (isset($updates['products'])) {
            foreach ($updates['products'] as $product) {
                if (applyProductUpdate($product)) {
                    $applied_count++;
                }
            }
        }
        
        // Puan güncellemeleri
        if (isset($updates['points'])) {
            foreach ($updates['points'] as $point) {
                if (applyPointUpdate($point)) {
                    $applied_count++;
                }
            }
        }
        
        // Sistem ayarları güncellemeleri
        if (isset($updates['settings'])) {
            foreach ($updates['settings'] as $setting) {
                if (applySettingUpdate($setting)) {
                    $applied_count++;
                }
            }
        }
        
        $conn->commit();
        
        return $applied_count;
        
    } catch (Exception $e) {
        $conn->rollBack();
        error_log('Güncelleme uygulama hatası: ' . $e->getMessage());
        throw new Exception('Güncellemeler uygulanamadı: ' . $e->getMessage());
    }
}

/**
 * Müşteri güncellemesini uygula
 */
function applyCustomerUpdate($customer_data) {
    global $conn;
    
    try {
        // Mevcut müşteri var mı kontrol et
        $stmt = $conn->prepare("SELECT id, last_updated FROM musteriler WHERE id = ?");
        $stmt->execute([$customer_data['id']]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $update_time = $customer_data['last_updated'] ?? date('Y-m-d H:i:s');
        
        if ($existing) {
            // Çakışma kontrolü - son güncelleme zamanı
            if (strtotime($existing['last_updated']) > strtotime($update_time)) {
                // Local data daha yeni, güncelleme
                logConflict('customer_update', $customer_data['id'], 
                           'Local data newer than server data');
                return false;
            }
            
            // Güncelle
            $stmt = $conn->prepare("
                UPDATE musteriler 
                SET ad = ?, soyad = ?, telefon = ?, email = ?, 
                    sms_aktif = ?, durum = ?, last_updated = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $customer_data['ad'],
                $customer_data['soyad'], 
                $customer_data['telefon'],
                $customer_data['email'],
                $customer_data['sms_aktif'],
                $customer_data['durum'],
                $update_time,
                $customer_data['id']
            ]);
        } else {
            // Yeni müşteri ekle
            $stmt = $conn->prepare("
                INSERT INTO musteriler 
                (id, ad, soyad, telefon, email, barkod, sms_aktif, durum, kayit_tarihi, last_updated)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $customer_data['id'],
                $customer_data['ad'],
                $customer_data['soyad'],
                $customer_data['telefon'],
                $customer_data['email'],
                $customer_data['barkod'],
                $customer_data['sms_aktif'],
                $customer_data['durum'],
                $customer_data['kayit_tarihi'],
                $update_time
            ]);
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log('Müşteri güncelleme hatası: ' . $e->getMessage());
        return false;
    }
}

/**
 * Puan güncellemesini uygula
 */
function applyPointUpdate($point_data) {
    global $conn;
    
    try {
        // Müşteri puan kaydı var mı kontrol et
        $stmt = $conn->prepare("SELECT * FROM musteri_puanlar WHERE musteri_id = ?");
        $stmt->execute([$point_data['musteri_id']]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // Güncelle
            $stmt = $conn->prepare("
                UPDATE musteri_puanlar 
                SET puan_bakiye = ?, puan_oran = ?, musteri_turu = ?, 
                    son_alisveris_tarihi = ?
                WHERE musteri_id = ?
            ");
            $stmt->execute([
                $point_data['puan_bakiye'],
                $point_data['puan_oran'],
                $point_data['musteri_turu'],
                $point_data['son_alisveris_tarihi'],
                $point_data['musteri_id']
            ]);
        } else {
            // Yeni kayıt ekle
            $stmt = $conn->prepare("
                INSERT INTO musteri_puanlar 
                (musteri_id, puan_bakiye, puan_oran, musteri_turu, son_alisveris_tarihi)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $point_data['musteri_id'],
                $point_data['puan_bakiye'],
                $point_data['puan_oran'],
                $point_data['musteri_turu'],
                $point_data['son_alisveris_tarihi']
            ]);
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log('Puan güncelleme hatası: ' . $e->getMessage());
        return false;
    }
}

/**
 * Ürün güncellemesini uygula
 */
function applyProductUpdate($product_data) {
    global $conn;
    
    try {
        // Mevcut ürün var mı kontrol et
        $stmt = $conn->prepare("SELECT id, last_updated FROM urun_stok WHERE id = ?");
        $stmt->execute([$product_data['id']]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $update_time = $product_data['last_updated'] ?? date('Y-m-d H:i:s');
        
        if ($existing) {
            // Çakışma kontrolü
            if (strtotime($existing['last_updated']) > strtotime($update_time)) {
                logConflict('product_update', $product_data['id'], 
                           'Local product newer than server data');
                return false;
            }
            
            // Ürün güncelle (fiyat bilgileri hariç stok)
            $stmt = $conn->prepare("
                UPDATE urun_stok 
                SET ad = ?, satis_fiyati = ?, alis_fiyati = ?, 
                    indirimli_fiyat = ?, indirim_baslangic_tarihi = ?, 
                    indirim_bitis_tarihi = ?, durum = ?, last_updated = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $product_data['ad'],
                $product_data['satis_fiyati'],
                $product_data['alis_fiyati'],
                $product_data['indirimli_fiyat'],
                $product_data['indirim_baslangic_tarihi'],
                $product_data['indirim_bitis_tarihi'],
                $product_data['durum'],
                $update_time,
                $product_data['id']
            ]);
        }
        // Not: Yeni ürünler admin panelden eklenir, POS'tan eklenmez
        
        return true;
        
    } catch (Exception $e) {
        error_log('Ürün güncelleme hatası: ' . $e->getMessage());
        return false;
    }
}

/**
 * Sistem ayarı güncellemesini uygula
 */
function applySettingUpdate($setting_data) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            INSERT INTO sistem_ayarlari (anahtar, deger, aciklama, guncelleme_tarihi)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
            deger = VALUES(deger),
            aciklama = VALUES(aciklama),
            guncelleme_tarihi = NOW()
        ");
        
        $stmt->execute([
            $setting_data['anahtar'],
            $setting_data['deger'],
            $setting_data['aciklama'] ?? 'Ana sunucudan sync'
        ]);
        
        return true;
        
    } catch (Exception $e) {
        error_log('Ayar güncelleme hatası: ' . $e->getMessage());
        return false;
    }
}

/**
 * Ana sunucu API çağrısı
 */
function callMainServerAPI($endpoint, $params = []) {
    $main_server_url = getMainServerUrl() . $endpoint;
    
    // GET parametrelerini oluştur
    if (!empty($params)) {
        $main_server_url .= '?' . http_build_query($params);
    }
    
    // API key ve HMAC imza
    $api_key = getApiKey();
    $timestamp = time();
    $payload = json_encode($params);
    $signature = hash_hmac('sha256', $payload . $timestamp, getWebhookSecret());
    
    // cURL isteği
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $main_server_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-API-Key: ' . $api_key,
            'X-Webhook-Signature: ' . $signature,
            'X-Timestamp: ' . $timestamp,
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
 * Ana sunucu erişilebilirlik kontrolü
 */
function isMainServerReachable() {
    try {
        $main_server_url = getMainServerUrl();
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $main_server_url . '/api/sync/ping.php',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_NOBODY => true,
            CURLOPT_SSL_VERIFYPEER => false
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
 * Son sync zamanını al
 */
function getLastSyncTimestamp($store_id) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT UNIX_TIMESTAMP(son_sync_tarihi) as timestamp
        FROM sync_metadata 
        WHERE magaza_id = ? AND tablo_adi = 'general_updates'
    ");
    $stmt->execute([$store_id]);
    $result = $stmt->fetchColumn();
    
    return $result ?: (time() - 86400); // Son 24 saat
}

/**
 * Son sync zamanını güncelle
 */
function updateLastSyncTimestamp($store_id, $timestamp) {
    global $conn;
    
    $stmt = $conn->prepare("
        INSERT INTO sync_metadata 
        (magaza_id, tablo_adi, son_sync_tarihi, sync_durumu, operation_count)
        VALUES (?, 'general_updates', FROM_UNIXTIME(?), 'basarili', 1)
        ON DUPLICATE KEY UPDATE
        son_sync_tarihi = FROM_UNIXTIME(?),
        sync_durumu = 'basarili',
        operation_count = operation_count + 1
    ");
    $stmt->execute([$store_id, $timestamp, $timestamp]);
}

/**
 * Çakışma log'a kaydet
 */
function logConflict($type, $record_id, $description) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            INSERT INTO conflict_log 
            (conflict_type, magaza_id, record_id, conflict_data, resolution_type, created_at, notes)
            VALUES ('data_conflict', ?, ?, ?, 'pending', NOW(), ?)
        ");
        
        $conflict_data = json_encode([
            'type' => $type,
            'record_id' => $record_id,
            'description' => $description,
            'timestamp' => time()
        ]);
        
        $stmt->execute([
            getCurrentStoreId(),
            $record_id,
            $conflict_data,
            $description
        ]);
        
    } catch (Exception $e) {
        error_log('Çakışma log hatası: ' . $e->getMessage());
    }
}

/**
 * Sistem ayarlarından değer al
 */
function getMainServerUrl() {
    global $conn;
    
    $stmt = $conn->prepare("SELECT deger FROM sistem_ayarlari WHERE anahtar = 'main_server_url'");
    $stmt->execute();
    $result = $stmt->fetchColumn();
    
    return $result ?: 'https://pos.incikirtasiye.com';
}

function getApiKey() {
    global $conn;
    
    $stmt = $conn->prepare("SELECT deger FROM sistem_ayarlari WHERE anahtar = 'api_key'");
    $stmt->execute();
    $result = $stmt->fetchColumn();
    
    return $result ?: 'default_api_key_2024';
}

function getWebhookSecret() {
    global $conn;
    
    $stmt = $conn->prepare("SELECT deger FROM sistem_ayarlari WHERE anahtar = 'webhook_secret'");
    $stmt->execute();
    $result = $stmt->fetchColumn();
    
    return $result ?: 'pos_webhook_secret_2024';
}

function getCurrentStoreId() {
    global $conn;
    
    if (isset($_SESSION['magaza_id'])) {
        return $_SESSION['magaza_id'];
    }
    
    $stmt = $conn->prepare("SELECT deger FROM sistem_ayarlari WHERE anahtar = 'magaza_id'");
    $stmt->execute();
    $result = $stmt->fetchColumn();
    
    return $result ?: 1;
}
?>