<?php
/**
 * MERKEZ MAĞAZA - WEBHOOK ALICI API'Sİ
 * Ana sunucudan gelen webhook'ları işler ve local DB'yi günceller
 * Hibrit Sync - Phase 1: Real-time data synchronization
 */

require_once '../../session_manager.php';
require_once '../../db_connection.php';
require_once '../../stock_functions.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Store-ID, X-Timestamp, X-Signature, X-API-Key');

// OPTIONS preflight için
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Güvenlik ve yapılandırma
$STORE_ID = 1; // Merkez mağaza
$API_KEY = 'merkez_sync_key_2025';
$MAX_SIGNATURE_AGE = 300; // 5 dakika

$response = [
    'success' => false,
    'message' => '',
    'processed_data' => [],
    'timestamp' => date('Y-m-d H:i:s')
];

try {
    // Sadece POST metodu kabul et
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Sadece POST metodu desteklenir');
    }
    
    // Güvenlik başlıklarını kontrol et
    $headers = getallheaders();
    $store_id = isset($headers['X-Store-ID']) ? $headers['X-Store-ID'] : null;
    $timestamp = isset($headers['X-Timestamp']) ? (int)$headers['X-Timestamp'] : null;
    $signature = isset($headers['X-Signature']) ? $headers['X-Signature'] : null;
    $api_key = isset($headers['X-API-Key']) ? $headers['X-API-Key'] : null;
    
    // Temel güvenlik kontrolleri
    if (!$store_id || !$timestamp || !$signature || !$api_key) {
        throw new Exception('Eksik güvenlik başlıkları');
    }
    
    if ($api_key !== $API_KEY) {
        throw new Exception('Geçersiz API anahtarı');
    }
    
    // Timestamp kontrolü (replay attack önleme)
    if (abs(time() - $timestamp) > $MAX_SIGNATURE_AGE) {
        throw new Exception('İstek çok eski veya gelecekten');
    }
    
    // Webhook verilerini al
    $webhook_data = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Geçersiz JSON verisi');
    }
    
    if (!isset($webhook_data['type']) || !isset($webhook_data['data'])) {
        throw new Exception('Webhook formatı hatalı');
    }
    
    // İmza doğrulama
    $message = $store_id . serialize($webhook_data) . $timestamp;
    $expected_signature = hash_hmac('sha256', $message, $API_KEY);
    
    if (!hash_equals($expected_signature, $signature)) {
        throw new Exception('İmza doğrulaması başarısız');
    }
    
    // Webhook tipine göre işlem yap
    $webhook_type = $webhook_data['type'];
    $data = $webhook_data['data'];
    
    switch ($webhook_type) {
        case 'product_update':
            $result = processProductUpdate($data);
            break;
            
        case 'price_update':
            $result = processPriceUpdate($data);
            break;
            
        case 'customer_update':
            $result = processCustomerUpdate($data);
            break;
            
        case 'stock_adjustment':
            $result = processStockAdjustment($data);
            break;
            
        case 'system_config':
            $result = processSystemConfig($data);
            break;
            
        case 'sale_notification':
            $result = processSaleNotification($data);
            break;
            
        case 'debt_update':
            $result = processDebtUpdate($data);
            break;
            
        case 'promotion_update':
            $result = processPromotionUpdate($data);
            break;
            
        default:
            throw new Exception("Desteklenmeyen webhook tipi: $webhook_type");
    }
    
    $response['success'] = true;
    $response['message'] = "Webhook başarıyla işlendi: $webhook_type";
    $response['processed_data'] = $result;
    
    // Webhook logla
    logWebhook($webhook_type, $data, true, $response['message']);
    
} catch (Exception $e) {
    $response['message'] = 'Webhook işleme hatası: ' . $e->getMessage();
    error_log('Webhook Error: ' . $e->getMessage());
    
    // Hata logla
    logWebhook($webhook_type ?? 'unknown', $webhook_data ?? [], false, $e->getMessage());
    
    http_response_code(400);
}

echo json_encode($response);

/**
 * Ürün güncelleme webhook'ını işle
 */
function processProductUpdate($data) {
    global $conn;
    
    $result = [];
    
    try {
        foreach ($data['products'] as $product) {
            $stmt = $conn->prepare("
                INSERT INTO urun_stok (
                    kod, barkod, ad, satis_fiyati, alis_fiyati, 
                    indirimli_fiyat, kdv_orani, departman_id, 
                    ana_grup_id, alt_grup_id, birim_id, durum,
                    indirim_baslangic_tarihi, indirim_bitis_tarihi,
                    last_updated
                ) VALUES (
                    :kod, :barkod, :ad, :satis_fiyati, :alis_fiyati,
                    :indirimli_fiyat, :kdv_orani, :departman_id,
                    :ana_grup_id, :alt_grup_id, :birim_id, :durum,
                    :indirim_baslangic, :indirim_bitis, NOW()
                ) ON DUPLICATE KEY UPDATE
                    ad = VALUES(ad),
                    satis_fiyati = VALUES(satis_fiyati),
                    alis_fiyati = VALUES(alis_fiyati),
                    indirimli_fiyat = VALUES(indirimli_fiyat),
                    kdv_orani = VALUES(kdv_orani),
                    departman_id = VALUES(departman_id),
                    ana_grup_id = VALUES(ana_grup_id),
                    alt_grup_id = VALUES(alt_grup_id),
                    birim_id = VALUES(birim_id),
                    durum = VALUES(durum),
                    indirim_baslangic_tarihi = VALUES(indirim_baslangic_tarihi),
                    indirim_bitis_tarihi = VALUES(indirim_bitis_tarihi),
                    last_updated = NOW()
            ");
            
            $stmt->execute([
                ':kod' => $product['kod'] ?? null,
                ':barkod' => $product['barkod'],
                ':ad' => $product['ad'],
                ':satis_fiyati' => $product['satis_fiyati'],
                ':alis_fiyati' => $product['alis_fiyati'] ?? null,
                ':indirimli_fiyat' => $product['indirimli_fiyat'] ?? null,
                ':kdv_orani' => $product['kdv_orani'] ?? 18,
                ':departman_id' => $product['departman_id'] ?? null,
                ':ana_grup_id' => $product['ana_grup_id'] ?? null,
                ':alt_grup_id' => $product['alt_grup_id'] ?? null,
                ':birim_id' => $product['birim_id'] ?? null,
                ':durum' => $product['durum'] ?? 'aktif',
                ':indirim_baslangic' => $product['indirim_baslangic_tarihi'] ?? null,
                ':indirim_bitis' => $product['indirim_bitis_tarihi'] ?? null
            ]);
            
            $result[] = [
                'barkod' => $product['barkod'],
                'action' => 'updated',
                'status' => 'success'
            ];
        }
        
    } catch (Exception $e) {
        throw new Exception('Ürün güncelleme hatası: ' . $e->getMessage());
    }
    
    return $result;
}

/**
 * Fiyat güncelleme webhook'ını işle
 */
function processPriceUpdate($data) {
    global $conn;
    
    $result = [];
    
    try {
        foreach ($data['price_updates'] as $price_update) {
            // Ürün fiyatını güncelle
            $stmt = $conn->prepare("
                UPDATE urun_stok 
                SET satis_fiyati = :new_price, 
                    indirimli_fiyat = :discounted_price,
                    indirim_baslangic_tarihi = :discount_start,
                    indirim_bitis_tarihi = :discount_end,
                    last_updated = NOW()
                WHERE barkod = :barkod
            ");
            
            $stmt->execute([
                ':new_price' => $price_update['satis_fiyati'],
                ':discounted_price' => $price_update['indirimli_fiyat'] ?? null,
                ':discount_start' => $price_update['indirim_baslangic_tarihi'] ?? null,
                ':discount_end' => $price_update['indirim_bitis_tarihi'] ?? null,
                ':barkod' => $price_update['barkod']
            ]);
            
            // Mağaza stoklarındaki fiyatı da güncelle
            $stmt2 = $conn->prepare("
                UPDATE magaza_stok 
                SET satis_fiyati = :new_price,
                    son_guncelleme = NOW()
                WHERE barkod = :barkod
            ");
            
            $stmt2->execute([
                ':new_price' => $price_update['satis_fiyati'],
                ':barkod' => $price_update['barkod']
            ]);
            
            // Fiyat geçmişine kaydet
            if (isset($price_update['eski_fiyat'])) {
                $stmt3 = $conn->prepare("
                    INSERT INTO urun_fiyat_gecmisi (
                        urun_id, islem_tipi, eski_fiyat, yeni_fiyat,
                        aciklama, tarih, kullanici_id
                    ) SELECT 
                        id, 'satis_fiyati_guncelleme', :eski_fiyat, :yeni_fiyat,
                        'Webhook ile otomatik güncelleme', NOW(), NULL
                    FROM urun_stok 
                    WHERE barkod = :barkod
                ");
                
                $stmt3->execute([
                    ':eski_fiyat' => $price_update['eski_fiyat'],
                    ':yeni_fiyat' => $price_update['satis_fiyati'],
                    ':barkod' => $price_update['barkod']
                ]);
            }
            
            $result[] = [
                'barkod' => $price_update['barkod'],
                'action' => 'price_updated',
                'old_price' => $price_update['eski_fiyat'] ?? null,
                'new_price' => $price_update['satis_fiyati'],
                'status' => 'success'
            ];
        }
        
    } catch (Exception $e) {
        throw new Exception('Fiyat güncelleme hatası: ' . $e->getMessage());
    }
    
    return $result;
}

/**
 * Müşteri güncelleme webhook'ını işle
 */
function processCustomerUpdate($data) {
    global $conn;
    
    $result = [];
    
    try {
        foreach ($data['customers'] as $customer) {
            // Müşteri bilgilerini güncelle
            $stmt = $conn->prepare("
                INSERT INTO musteriler (
                    id, ad, soyad, telefon, email, barkod,
                    sms_aktif, durum, last_updated
                ) VALUES (
                    :id, :ad, :soyad, :telefon, :email, :barkod,
                    :sms_aktif, :durum, NOW()
                ) ON DUPLICATE KEY UPDATE
                    ad = VALUES(ad),
                    soyad = VALUES(soyad),
                    telefon = VALUES(telefon),
                    email = VALUES(email),
                    barkod = VALUES(barkod),
                    sms_aktif = VALUES(sms_aktif),
                    durum = VALUES(durum),
                    last_updated = NOW()
            ");
            
            $stmt->execute([
                ':id' => $customer['id'],
                ':ad' => $customer['ad'],
                ':soyad' => $customer['soyad'],
                ':telefon' => $customer['telefon'],
                ':email' => $customer['email'] ?? null,
                ':barkod' => $customer['barkod'] ?? null,
                ':sms_aktif' => $customer['sms_aktif'] ?? 1,
                ':durum' => $customer['durum'] ?? 'aktif'
            ]);
            
            // Puan bilgilerini güncelle
            if (isset($customer['puan_bilgileri'])) {
                $puan = $customer['puan_bilgileri'];
                
                $stmt2 = $conn->prepare("
                    INSERT INTO musteri_puanlar (
                        musteri_id, puan_bakiye, puan_oran, musteri_turu
                    ) VALUES (
                        :musteri_id, :puan_bakiye, :puan_oran, :musteri_turu
                    ) ON DUPLICATE KEY UPDATE
                        puan_bakiye = VALUES(puan_bakiye),
                        puan_oran = VALUES(puan_oran),
                        musteri_turu = VALUES(musteri_turu)
                ");
                
                $stmt2->execute([
                    ':musteri_id' => $customer['id'],
                    ':puan_bakiye' => $puan['puan_bakiye'] ?? 0,
                    ':puan_oran' => $puan['puan_oran'] ?? 1,
                    ':musteri_turu' => $puan['musteri_turu'] ?? 'standart'
                ]);
            }
            
            $result[] = [
                'customer_id' => $customer['id'],
                'action' => 'updated',
                'status' => 'success'
            ];
        }
        
    } catch (Exception $e) {
        throw new Exception('Müşteri güncelleme hatası: ' . $e->getMessage());
    }
    
    return $result;
}

/**
 * Stok düzeltme webhook'ını işle
 */
function processStockAdjustment($data) {
    global $conn, $STORE_ID;
    
    $result = [];
    
    try {
        foreach ($data['adjustments'] as $adjustment) {
            // Stok düzeltmesini uygula
            if ($adjustment['location'] === 'magaza') {
                // Mağaza stoğu güncelle
                $stmt = $conn->prepare("
                    UPDATE magaza_stok 
                    SET stok_miktari = :new_amount,
                        son_guncelleme = NOW()
                    WHERE barkod = :barkod AND magaza_id = :magaza_id
                ");
                
                $stmt->execute([
                    ':new_amount' => $adjustment['new_amount'],
                    ':barkod' => $adjustment['barkod'],
                    ':magaza_id' => $STORE_ID
                ]);
                
            } else if ($adjustment['location'] === 'depo') {
                // Depo stoğu güncelle
                $stmt = $conn->prepare("
                    UPDATE depo_stok ds
                    JOIN urun_stok us ON ds.urun_id = us.id
                    SET ds.stok_miktari = :new_amount,
                        ds.son_guncelleme = NOW()
                    WHERE us.barkod = :barkod AND ds.depo_id = :depo_id
                ");
                
                $stmt->execute([
                    ':new_amount' => $adjustment['new_amount'],
                    ':barkod' => $adjustment['barkod'],
                    ':depo_id' => $adjustment['depo_id'] ?? 1
                ]);
            }
            
            // Stok hareketini kaydet
            $stmt3 = $conn->prepare("
                INSERT INTO stok_hareketleri (
                    urun_id, miktar, hareket_tipi, aciklama,
                    tarih, kullanici_id, magaza_id, depo_id
                ) SELECT 
                    us.id, 
                    :miktar_degisim,
                    :hareket_tipi,
                    :aciklama,
                    NOW(),
                    NULL,
                    :magaza_id,
                    :depo_id
                FROM urun_stok us 
                WHERE us.barkod = :barkod
            ");
            
            $miktar_degisim = $adjustment['new_amount'] - $adjustment['old_amount'];
            
            $stmt3->execute([
                ':miktar_degisim' => $miktar_degisim,
                ':hareket_tipi' => $miktar_degisim > 0 ? 'giris' : 'cikis',
                ':aciklama' => 'Webhook ile stok düzeltmesi: ' . ($adjustment['reason'] ?? 'Otomatik'),
                ':magaza_id' => $adjustment['location'] === 'magaza' ? $STORE_ID : null,
                ':depo_id' => $adjustment['location'] === 'depo' ? ($adjustment['depo_id'] ?? 1) : null,
                ':barkod' => $adjustment['barkod']
            ]);
            
            // Ana stok tablosunu güncelle
            $stmt4 = $conn->prepare("SELECT id FROM urun_stok WHERE barkod = :barkod");
            $stmt4->execute([':barkod' => $adjustment['barkod']]);
            $urun_id = $stmt4->fetchColumn();
            
            if ($urun_id) {
                updateProductTotalStock($urun_id, $conn);
            }
            
            $result[] = [
                'barkod' => $adjustment['barkod'],
                'location' => $adjustment['location'],
                'old_amount' => $adjustment['old_amount'],
                'new_amount' => $adjustment['new_amount'],
                'action' => 'stock_adjusted',
                'status' => 'success'
            ];
        }
        
    } catch (Exception $e) {
        throw new Exception('Stok düzeltme hatası: ' . $e->getMessage());
    }
    
    return $result;
}

/**
 * Sistem ayarları webhook'ını işle
 */
function processSystemConfig($data) {
    global $conn;
    
    $result = [];
    
    try {
        foreach ($data['configs'] as $config) {
            $stmt = $conn->prepare("
                INSERT INTO sistem_ayarlari (anahtar, deger, aciklama, guncelleme_tarihi)
                VALUES (:anahtar, :deger, :aciklama, NOW())
                ON DUPLICATE KEY UPDATE
                    deger = VALUES(deger),
                    aciklama = VALUES(aciklama),
                    guncelleme_tarihi = NOW()
            ");
            
            $stmt->execute([
                ':anahtar' => $config['key'],
                ':deger' => $config['value'],
                ':aciklama' => $config['description'] ?? 'Webhook ile güncellendi'
            ]);
            
            $result[] = [
                'config_key' => $config['key'],
                'action' => 'updated',
                'status' => 'success'
            ];
        }
        
    } catch (Exception $e) {
        throw new Exception('Sistem ayarları güncelleme hatası: ' . $e->getMessage());
    }
    
    return $result;
}

/**
 * Satış bildirimi webhook'ını işle (Dolunay'dan gelen satışlar)
 */
function processSaleNotification($data) {
    global $conn;
    
    $result = [];
    
    try {
        foreach ($data['sales'] as $sale_info) {
            // Sadece bilgi amaçlı - yerel stok etkilenmez
            // Ama müşteri puanları güncellenebilir
            
            if (isset($sale_info['customer_points'])) {
                $points = $sale_info['customer_points'];
                
                // Müşteri puan bilgilerini güncelle
                $stmt = $conn->prepare("
                    UPDATE musteri_puanlar 
                    SET puan_bakiye = :puan_bakiye,
                        son_alisveris_tarihi = :son_alisveris
                    WHERE musteri_id = :musteri_id
                ");
                
                $stmt->execute([
                    ':puan_bakiye' => $points['new_balance'],
                    ':son_alisveris' => $sale_info['sale_date'],
                    ':musteri_id' => $points['customer_id']
                ]);
            }
            
            $result[] = [
                'sale_id' => $sale_info['sale_id'],
                'source_store' => $sale_info['source_store'],
                'action' => 'notification_processed',
                'status' => 'success'
            ];
        }
        
    } catch (Exception $e) {
        throw new Exception('Satış bildirimi hatası: ' . $e->getMessage());
    }
    
    return $result;
}

/**
 * Borç güncelleme webhook'ını işle
 */
function processDebtUpdate($data) {
    global $conn;
    
    $result = [];
    
    try {
        foreach ($data['debt_updates'] as $debt) {
            if ($debt['action'] === 'create') {
                // Yeni borç kaydı
                $stmt = $conn->prepare("
                    INSERT INTO musteri_borclar (
                        musteri_id, toplam_tutar, indirim_tutari,
                        borc_tarihi, fis_no, magaza_id, odendi_mi
                    ) VALUES (
                        :musteri_id, :toplam_tutar, :indirim_tutari,
                        :borc_tarihi, :fis_no, :magaza_id, :odendi_mi
                    )
                ");
                
                $stmt->execute([
                    ':musteri_id' => $debt['musteri_id'],
                    ':toplam_tutar' => $debt['toplam_tutar'],
                    ':indirim_tutari' => $debt['indirim_tutari'] ?? 0,
                    ':borc_tarihi' => $debt['borc_tarihi'],
                    ':fis_no' => $debt['fis_no'],
                    ':magaza_id' => $debt['magaza_id'],
                    ':odendi_mi' => $debt['odendi_mi'] ?? 0
                ]);
                
            } else if ($debt['action'] === 'payment') {
                // Borç ödeme kaydı
                $stmt = $conn->prepare("
                    INSERT INTO musteri_borc_odemeler (
                        borc_id, odeme_tutari, odeme_tarihi,
                        odeme_yontemi, aciklama
                    ) VALUES (
                        :borc_id, :odeme_tutari, :odeme_tarihi,
                        :odeme_yontemi, :aciklama
                    )
                ");
                
                $stmt->execute([
                    ':borc_id' => $debt['borc_id'],
                    ':odeme_tutari' => $debt['odeme_tutari'],
                    ':odeme_tarihi' => $debt['odeme_tarihi'],
                    ':odeme_yontemi' => $debt['odeme_yontemi'],
                    ':aciklama' => $debt['aciklama'] ?? 'Webhook ile güncellendi'
                ]);
                
                // Borç durumunu kontrol et ve güncelle
                $stmt2 = $conn->prepare("
                    UPDATE musteri_borclar mb
                    SET odendi_mi = CASE 
                        WHEN (
                            SELECT COALESCE(SUM(mbo.odeme_tutari), 0) 
                            FROM musteri_borc_odemeler mbo 
                            WHERE mbo.borc_id = mb.borc_id
                        ) >= (mb.toplam_tutar - mb.indirim_tutari) THEN 1
                        ELSE 0
                    END
                    WHERE mb.borc_id = :borc_id
                ");
                
                $stmt2->execute([':borc_id' => $debt['borc_id']]);
            }
            
            $result[] = [
                'customer_id' => $debt['musteri_id'],
                'action' => $debt['action'],
                'status' => 'success'
            ];
        }
        
    } catch (Exception $e) {
        throw new Exception('Borç güncelleme hatası: ' . $e->getMessage());
    }
    
    return $result;
}

/**
 * Promosyon güncelleme webhook'ını işle
 */
function processPromotionUpdate($data) {
    global $conn;
    
    $result = [];
    
    try {
        foreach ($data['promotions'] as $promotion) {
            // İndirim kampanyasını güncelle
            $stmt = $conn->prepare("
                INSERT INTO indirimler (
                    id, ad, indirim_turu, indirim_degeri,
                    baslangic_tarihi, bitis_tarihi, aciklama,
                    uygulama_turu, filtre_degeri, durum, kullanici_id
                ) VALUES (
                    :id, :ad, :indirim_turu, :indirim_degeri,
                    :baslangic_tarihi, :bitis_tarihi, :aciklama,
                    :uygulama_turu, :filtre_degeri, :durum, :kullanici_id
                ) ON DUPLICATE KEY UPDATE
                    ad = VALUES(ad),
                    indirim_turu = VALUES(indirim_turu),
                    indirim_degeri = VALUES(indirim_degeri),
                    baslangic_tarihi = VALUES(baslangic_tarihi),
                    bitis_tarihi = VALUES(bitis_tarihi),
                    aciklama = VALUES(aciklama),
                    uygulama_turu = VALUES(uygulama_turu),
                    filtre_degeri = VALUES(filtre_degeri),
                    durum = VALUES(durum)
            ");
            
            $stmt->execute([
                ':id' => $promotion['id'],
                ':ad' => $promotion['ad'],
                ':indirim_turu' => $promotion['indirim_turu'],
                ':indirim_degeri' => $promotion['indirim_degeri'],
                ':baslangic_tarihi' => $promotion['baslangic_tarihi'],
                ':bitis_tarihi' => $promotion['bitis_tarihi'],
                ':aciklama' => $promotion['aciklama'],
                ':uygulama_turu' => $promotion['uygulama_turu'],
                ':filtre_degeri' => $promotion['filtre_degeri'],
                ':durum' => $promotion['durum'],
                ':kullanici_id' => 1 // Sistem kullanıcısı
            ]);
            
            $result[] = [
                'promotion_id' => $promotion['id'],
                'action' => 'updated',
                'status' => 'success'
            ];
        }
        
    } catch (Exception $e) {
        throw new Exception('Promosyon güncelleme hatası: ' . $e->getMessage());
    }
    
    return $result;
}

/**
 * Webhook işlemini logla
 */
function logWebhook($type, $data, $success, $message) {
    global $conn, $STORE_ID;
    
    try {
        // sync_queue tablosuna log kaydı ekle
        $stmt = $conn->prepare("
            INSERT INTO sync_queue (
                magaza_id, operation_type, table_name, 
                data_json, status, created_at, processed_at,
                error_message
            ) VALUES (
                :magaza_id, 'webhook_received', :table_name,
                :data_json, :status, NOW(), NOW(),
                :error_message
            )
        ");
        
        $stmt->execute([
            ':magaza_id' => $STORE_ID,
            ':table_name' => $type,
            ':data_json' => json_encode($data),
            ':status' => $success ? 'completed' : 'failed',
            ':error_message' => $success ? null : $message
        ]);
        
    } catch (Exception $e) {
        error_log('Webhook logging failed: ' . $e->getMessage());
    }
}
?>