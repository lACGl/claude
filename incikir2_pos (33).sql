-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Anamakine: localhost:3306
-- Üretim Zamanı: 01 Haz 2025, 21:48:49
-- Sunucu sürümü: 5.7.44
-- PHP Sürümü: 8.1.31

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Veritabanı: `incikir2_pos`
--

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `admin_user`
--

CREATE TABLE `admin_user` (
  `id` int(11) NOT NULL,
  `kullanici_adi` varchar(50) NOT NULL,
  `sifre` varchar(255) NOT NULL,
  `telefon_no` varchar(15) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `alis_faturalari`
--

CREATE TABLE `alis_faturalari` (
  `id` int(11) NOT NULL,
  `fatura_tipi` enum('satis','iade') DEFAULT 'satis',
  `magaza` int(11) DEFAULT NULL,
  `fatura_seri` varchar(20) DEFAULT NULL,
  `fatura_no` varchar(20) DEFAULT NULL,
  `fatura_tarihi` date DEFAULT NULL,
  `irsaliye_no` varchar(50) DEFAULT NULL,
  `irsaliye_tarihi` date DEFAULT NULL,
  `siparis_no` varchar(50) DEFAULT NULL,
  `siparis_tarihi` date DEFAULT NULL,
  `tedarikci` int(11) DEFAULT NULL,
  `durum` enum('bos','urun_girildi','aktarim_bekliyor','kismi_aktarildi','aktarildi') DEFAULT 'bos',
  `toplam_tutar` decimal(10,2) DEFAULT '0.00',
  `kdv_tutari` decimal(10,2) DEFAULT '0.00',
  `net_tutar` decimal(10,2) DEFAULT '0.00',
  `aciklama` text,
  `kayit_tarihi` datetime DEFAULT CURRENT_TIMESTAMP,
  `kullanici_id` int(11) DEFAULT NULL,
  `aktarim_tarihi` datetime DEFAULT NULL,
  `aktarilan_miktar` decimal(10,2) DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `alis_fatura_aktarim`
--

CREATE TABLE `alis_fatura_aktarim` (
  `id` int(11) NOT NULL,
  `fatura_id` int(11) NOT NULL,
  `magaza_id` int(11) DEFAULT NULL,
  `depo_id` int(11) DEFAULT NULL,
  `aktarim_tarihi` datetime DEFAULT CURRENT_TIMESTAMP,
  `kullanici_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `alis_fatura_detay`
--

CREATE TABLE `alis_fatura_detay` (
  `id` int(11) NOT NULL,
  `fatura_id` int(11) NOT NULL,
  `urun_id` int(11) NOT NULL,
  `miktar` int(11) NOT NULL,
  `birim_fiyat` decimal(10,2) NOT NULL,
  `iskonto1` int(3) DEFAULT '0',
  `iskonto2` int(3) DEFAULT '0',
  `iskonto3` int(3) DEFAULT '0',
  `kdv_orani` int(3) DEFAULT NULL,
  `toplam_tutar` decimal(10,2) NOT NULL,
  `kayit_tarihi` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `alis_fatura_detay_aktarim`
--

CREATE TABLE `alis_fatura_detay_aktarim` (
  `id` int(11) NOT NULL,
  `fatura_id` int(11) NOT NULL,
  `urun_id` int(11) NOT NULL,
  `miktar` decimal(10,2) NOT NULL,
  `kalan_miktar` decimal(10,2) DEFAULT '0.00',
  `aktarim_tarihi` datetime NOT NULL,
  `magaza_id` int(11) DEFAULT NULL,
  `depo_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `alis_fatura_log`
--

CREATE TABLE `alis_fatura_log` (
  `id` int(11) NOT NULL,
  `fatura_id` int(11) DEFAULT NULL,
  `islem_tipi` varchar(50) DEFAULT NULL,
  `aciklama` text,
  `kullanici_id` int(11) DEFAULT NULL,
  `tarih` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `alt_gruplar`
--

CREATE TABLE `alt_gruplar` (
  `id` int(11) NOT NULL,
  `ad` varchar(100) NOT NULL,
  `ana_grup_id` int(11) NOT NULL,
  `kayit_tarihi` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `ana_gruplar`
--

CREATE TABLE `ana_gruplar` (
  `id` int(11) NOT NULL,
  `ad` varchar(100) NOT NULL,
  `departman_id` int(11) DEFAULT NULL,
  `kayit_tarihi` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `barcode_log`
--

CREATE TABLE `barcode_log` (
  `id` int(11) NOT NULL,
  `urun_id` int(11) NOT NULL,
  `islem_tipi` enum('olusturma','yazdirma','guncelleme') NOT NULL,
  `aciklama` text,
  `kullanici_id` int(11) DEFAULT NULL,
  `kullanici_tipi` enum('admin','personel') DEFAULT NULL,
  `ip_adresi` varchar(45) DEFAULT NULL,
  `islem_tarihi` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `barcode_settings`
--

CREATE TABLE `barcode_settings` (
  `id` int(11) NOT NULL,
  `anahtar` varchar(50) NOT NULL,
  `deger` text,
  `aciklama` varchar(255) DEFAULT NULL,
  `guncelleme_tarihi` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `birimler`
--

CREATE TABLE `birimler` (
  `id` int(11) NOT NULL,
  `ad` varchar(50) NOT NULL,
  `kayit_tarihi` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `conflict_log`
--

CREATE TABLE `conflict_log` (
  `id` int(11) NOT NULL,
  `conflict_type` enum('stock_conflict','invoice_conflict','data_conflict') NOT NULL,
  `magaza_id` int(11) NOT NULL,
  `urun_id` int(11) DEFAULT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `conflict_data` json DEFAULT NULL,
  `resolution_type` enum('auto_resolved','manual_resolved','pending','ignored') DEFAULT 'pending',
  `resolved_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` datetime DEFAULT NULL,
  `notes` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Çakışma kayıtları ve çözümleri';

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `departmanlar`
--

CREATE TABLE `departmanlar` (
  `id` int(11) NOT NULL,
  `ad` varchar(100) NOT NULL,
  `kayit_tarihi` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `depolar`
--

CREATE TABLE `depolar` (
  `id` int(11) NOT NULL,
  `kod` varchar(50) DEFAULT NULL,
  `ad` varchar(100) DEFAULT NULL,
  `adres` varchar(255) DEFAULT NULL,
  `telefon` varchar(20) DEFAULT NULL,
  `depo_tipi` enum('ana_depo','ara_depo') DEFAULT 'ana_depo',
  `durum` enum('aktif','pasif') DEFAULT 'aktif',
  `kayit_tarihi` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `depo_stok`
--

CREATE TABLE `depo_stok` (
  `id` int(11) NOT NULL,
  `depo_id` int(11) DEFAULT NULL,
  `urun_id` int(11) NOT NULL,
  `stok_miktari` int(11) DEFAULT NULL,
  `min_stok` int(11) DEFAULT NULL,
  `max_stok` int(11) DEFAULT NULL,
  `son_guncelleme` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `fatura_erisim_token`
--

CREATE TABLE `fatura_erisim_token` (
  `id` int(11) NOT NULL,
  `fatura_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `olusturma_tarihi` datetime NOT NULL,
  `son_gecerlilik` datetime NOT NULL,
  `kullanim_sayisi` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `fiyat_gor`
--

CREATE TABLE `fiyat_gor` (
  `barkod` varchar(100) NOT NULL,
  `kod` varchar(100) DEFAULT NULL,
  `ad` varchar(255) DEFAULT NULL,
  `satis_fiyati` decimal(10,2) DEFAULT NULL,
  `indirimli_fiyat` decimal(10,2) DEFAULT NULL,
  `indirim_bitis_tarihi` datetime DEFAULT NULL,
  `stok_miktari` int(11) DEFAULT NULL,
  `guncelleme_zamani` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `iletisim_log`
--

CREATE TABLE `iletisim_log` (
  `id` int(11) NOT NULL,
  `tur` enum('email','sms') NOT NULL,
  `alici` varchar(255) NOT NULL,
  `konu` varchar(255) DEFAULT NULL,
  `icerik` text NOT NULL,
  `fatura_id` int(11) DEFAULT NULL,
  `musteri_id` int(11) DEFAULT NULL,
  `tarih` datetime NOT NULL,
  `erisim_link` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `incibot_log`
--

CREATE TABLE `incibot_log` (
  `id` int(11) NOT NULL,
  `kullanici_id` int(11) DEFAULT NULL,
  `sorgu` text NOT NULL,
  `yanit` text NOT NULL,
  `tarih` datetime DEFAULT CURRENT_TIMESTAMP,
  `ip_adresi` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `indirimler`
--

CREATE TABLE `indirimler` (
  `id` int(11) NOT NULL,
  `ad` varchar(255) NOT NULL COMMENT 'İndirim adı',
  `indirim_turu` enum('yuzde','tutar') NOT NULL DEFAULT 'yuzde' COMMENT 'Yüzde veya Tutar',
  `indirim_degeri` decimal(10,2) NOT NULL COMMENT 'İndirim değeri (yüzde veya tutar)',
  `baslangic_tarihi` date NOT NULL COMMENT 'İndirim başlangıç tarihi',
  `bitis_tarihi` date NOT NULL COMMENT 'İndirim bitiş tarihi',
  `aciklama` text COMMENT 'İndirim açıklaması',
  `uygulama_turu` enum('tum','secili','departman','ana_grup') NOT NULL DEFAULT 'tum' COMMENT 'Uygulama türü',
  `filtre_degeri` varchar(255) DEFAULT NULL COMMENT 'Filtre değeri (ürün ID''leri, departman ID''si, ana grup ID''si)',
  `olusturulma_tarihi` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `durum` enum('aktif','pasif') NOT NULL DEFAULT 'aktif',
  `kullanici_id` int(11) NOT NULL COMMENT 'İndirimi oluşturan kullanıcı'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Ürün indirimleri tablosu';

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `indirim_detay`
--

CREATE TABLE `indirim_detay` (
  `id` int(11) NOT NULL,
  `indirim_id` int(11) NOT NULL COMMENT 'İndirim ID',
  `urun_id` int(11) NOT NULL COMMENT 'Ürün ID',
  `eski_fiyat` decimal(10,2) NOT NULL COMMENT 'İndirim öncesi fiyat',
  `indirimli_fiyat` decimal(10,2) NOT NULL COMMENT 'İndirim sonrası fiyat',
  `uygulama_tarihi` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='İndirim detayları tablosu';

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `invoice_sequences`
--

CREATE TABLE `invoice_sequences` (
  `magaza_id` int(11) NOT NULL,
  `sequence_date` date NOT NULL,
  `last_number` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Mağaza bazında fatura numarası sıralaması';

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `kullanici_ban`
--

CREATE TABLE `kullanici_ban` (
  `id` int(11) NOT NULL,
  `kullanici_id` int(11) NOT NULL,
  `kullanici_tipi` enum('admin','personel') NOT NULL,
  `ban_baslangic` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ban_bitis` datetime NOT NULL,
  `sebep` varchar(255) NOT NULL,
  `ip_adresi` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `kullanici_giris_log`
--

CREATE TABLE `kullanici_giris_log` (
  `id` int(11) NOT NULL,
  `kullanici_id` int(11) NOT NULL,
  `kullanici_tipi` enum('admin','personel') NOT NULL,
  `islem_tipi` enum('giris','cikis','dogrulama','basarisiz') NOT NULL,
  `ip_adresi` varchar(45) DEFAULT NULL,
  `tarayici_bilgisi` varchar(255) DEFAULT NULL,
  `tarih` datetime DEFAULT CURRENT_TIMESTAMP,
  `detay` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Kullanıcı giriş ve doğrulama işlemleri log tablosu';

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL,
  `ip_adresi` varchar(45) NOT NULL,
  `username_attempt` varchar(50) DEFAULT NULL,
  `zaman` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `basarili` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `login_ban`
--

CREATE TABLE `login_ban` (
  `id` int(11) NOT NULL,
  `ip_adresi` varchar(45) NOT NULL,
  `username_attempt` varchar(50) DEFAULT NULL,
  `ban_baslangic` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ban_bitis` datetime NOT NULL,
  `sebep` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `magazalar`
--

CREATE TABLE `magazalar` (
  `id` int(11) NOT NULL,
  `ad` varchar(100) DEFAULT NULL,
  `adres` varchar(255) DEFAULT NULL,
  `telefon` varchar(20) DEFAULT NULL,
  `cep_telefon` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `magaza_stok`
--

CREATE TABLE `magaza_stok` (
  `id` int(11) NOT NULL,
  `barkod` varchar(50) DEFAULT NULL,
  `magaza_id` int(11) DEFAULT NULL,
  `stok_miktari` int(11) DEFAULT NULL,
  `satis_fiyati` decimal(10,2) DEFAULT NULL,
  `son_guncelleme` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `musteriler`
--

CREATE TABLE `musteriler` (
  `id` int(11) NOT NULL,
  `ad` varchar(50) COLLATE utf8mb4_turkish_ci NOT NULL,
  `soyad` varchar(50) COLLATE utf8mb4_turkish_ci NOT NULL,
  `telefon` varchar(15) COLLATE utf8mb4_turkish_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_turkish_ci DEFAULT NULL,
  `kayit_tarihi` datetime DEFAULT CURRENT_TIMESTAMP,
  `barkod` varchar(255) COLLATE utf8mb4_turkish_ci DEFAULT NULL,
  `sms_aktif` tinyint(1) DEFAULT '1',
  `durum` enum('aktif','pasif') COLLATE utf8mb4_turkish_ci DEFAULT 'aktif',
  `last_updated` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `musteri_borclar`
--

CREATE TABLE `musteri_borclar` (
  `borc_id` int(11) NOT NULL,
  `musteri_id` int(11) NOT NULL,
  `toplam_tutar` decimal(10,2) NOT NULL,
  `indirim_tutari` decimal(10,2) DEFAULT '0.00',
  `borc_tarihi` date NOT NULL,
  `fis_no` varchar(20) COLLATE utf8mb4_turkish_ci DEFAULT NULL,
  `odendi_mi` tinyint(1) DEFAULT '0',
  `olusturma_tarihi` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `magaza_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `musteri_borc_detaylar`
--

CREATE TABLE `musteri_borc_detaylar` (
  `detay_id` int(11) NOT NULL,
  `borc_id` int(11) NOT NULL,
  `urun_adi` varchar(100) COLLATE utf8mb4_turkish_ci NOT NULL,
  `miktar` int(11) NOT NULL,
  `tutar` decimal(10,2) NOT NULL,
  `urun_id` int(11) DEFAULT NULL,
  `olusturma_tarihi` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `musteri_borc_odemeler`
--

CREATE TABLE `musteri_borc_odemeler` (
  `odeme_id` int(11) NOT NULL,
  `borc_id` int(11) NOT NULL,
  `odeme_tutari` decimal(10,2) NOT NULL,
  `odeme_tarihi` date NOT NULL,
  `odeme_yontemi` enum('nakit','kredi_karti','havale') COLLATE utf8mb4_turkish_ci DEFAULT 'nakit',
  `aciklama` varchar(255) COLLATE utf8mb4_turkish_ci DEFAULT NULL,
  `kullanici_id` int(11) DEFAULT NULL,
  `olusturma_tarihi` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `musteri_puanlar`
--

CREATE TABLE `musteri_puanlar` (
  `id` int(11) NOT NULL,
  `musteri_id` int(11) NOT NULL,
  `puan_bakiye` decimal(10,2) DEFAULT '0.00',
  `puan_oran` decimal(5,2) DEFAULT NULL COMMENT 'TL başına kazanılan % puan',
  `son_alisveris_tarihi` datetime DEFAULT NULL,
  `musteri_turu` enum('standart','gold','platinum') COLLATE utf8mb4_turkish_ci DEFAULT 'standart',
  `barcode` varchar(255) COLLATE utf8mb4_turkish_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `offline_sales`
--

CREATE TABLE `offline_sales` (
  `id` int(11) NOT NULL,
  `magaza_id` int(11) NOT NULL,
  `local_invoice_id` varchar(50) NOT NULL,
  `sale_data` json NOT NULL,
  `items_data` json NOT NULL,
  `customer_data` json DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `synced_at` datetime DEFAULT NULL,
  `synced_invoice_id` int(11) DEFAULT NULL,
  `status` enum('pending','synced','failed','duplicate') DEFAULT 'pending',
  `error_message` text,
  `checksum` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Offline modda kaydedilen satışlar';

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `personel`
--

CREATE TABLE `personel` (
  `id` int(11) NOT NULL,
  `ad` varchar(100) DEFAULT NULL,
  `no` varchar(20) DEFAULT NULL,
  `kullanici_adi` varchar(50) DEFAULT NULL,
  `sifre` varchar(255) DEFAULT NULL,
  `telefon_no` varchar(15) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `yetki_seviyesi` enum('kasiyer','mudur_yardimcisi','mudur') DEFAULT NULL,
  `durum` enum('aktif','pasif') DEFAULT 'aktif',
  `kayit_tarihi` datetime DEFAULT CURRENT_TIMESTAMP,
  `son_giris` datetime DEFAULT NULL,
  `magaza_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `puan_ayarlari`
--

CREATE TABLE `puan_ayarlari` (
  `id` int(11) NOT NULL,
  `musteri_turu` enum('standart','gold','platinum') COLLATE utf8mb4_turkish_ci NOT NULL,
  `puan_oran` decimal(5,2) NOT NULL COMMENT 'TL başına kazanılan puan',
  `min_harcama` decimal(10,2) DEFAULT '0.00' COMMENT 'Bu seviye için minimum harcama',
  `guncelleme_tarihi` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `puan_harcama`
--

CREATE TABLE `puan_harcama` (
  `id` int(11) NOT NULL,
  `fatura_id` int(11) NOT NULL,
  `musteri_id` int(11) NOT NULL,
  `harcanan_puan` decimal(10,2) NOT NULL,
  `tarih` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `puan_kazanma`
--

CREATE TABLE `puan_kazanma` (
  `id` int(11) NOT NULL,
  `fatura_id` int(11) NOT NULL,
  `musteri_id` int(11) NOT NULL,
  `kazanilan_puan` decimal(10,2) NOT NULL,
  `odeme_tutari` decimal(10,2) NOT NULL,
  `tarih` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `satis_faturalari`
--

CREATE TABLE `satis_faturalari` (
  `id` int(11) NOT NULL,
  `fatura_turu` varchar(50) DEFAULT NULL,
  `magaza` int(11) DEFAULT NULL,
  `fatura_seri` varchar(20) DEFAULT NULL,
  `fatura_no` varchar(20) DEFAULT NULL,
  `fatura_tarihi` datetime DEFAULT NULL,
  `toplam_tutar` decimal(10,2) DEFAULT NULL,
  `personel` int(11) DEFAULT NULL,
  `kdv_tutari` decimal(10,2) DEFAULT NULL,
  `indirim_tutari` decimal(10,2) DEFAULT NULL,
  `net_tutar` decimal(10,2) DEFAULT NULL,
  `odeme_turu` enum('nakit','kredi_karti','havale') DEFAULT NULL,
  `islem_turu` enum('satis','iade') DEFAULT NULL,
  `aciklama` text,
  `kredi_karti_banka` enum('Ziraat','İş Bankası','Garanti','Yapı Kredi','Akbank','Vakıfbank','QNB','Halkbank','Denizbank','TEB','Şekerbank','ING','HSBC') DEFAULT NULL,
  `musteri_id` int(11) DEFAULT NULL,
  `iliskili_fatura_id` int(11) DEFAULT NULL,
  `last_updated` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `sync_durumu` tinyint(4) DEFAULT '0',
  `sync_tarihi` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `satis_fatura_detay`
--

CREATE TABLE `satis_fatura_detay` (
  `id` int(11) NOT NULL,
  `fatura_id` int(11) DEFAULT NULL,
  `urun_id` int(11) DEFAULT NULL,
  `miktar` int(11) DEFAULT NULL,
  `birim_fiyat` decimal(10,2) DEFAULT NULL,
  `kdv_orani` decimal(5,2) DEFAULT NULL,
  `indirim_orani` decimal(5,2) DEFAULT NULL,
  `toplam_tutar` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `siparisler`
--

CREATE TABLE `siparisler` (
  `id` int(11) NOT NULL,
  `tedarikci_id` int(11) NOT NULL,
  `tarih` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `durum` enum('beklemede','onaylandi','iptal','tamamlandi') NOT NULL DEFAULT 'beklemede',
  `notlar` text,
  `kullanici_id` int(11) DEFAULT NULL,
  `onay_tarihi` datetime DEFAULT NULL,
  `teslim_tarihi` datetime DEFAULT NULL,
  `toplam_tutar` decimal(10,2) DEFAULT '0.00',
  `son_guncelleme` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `siparis_detay`
--

CREATE TABLE `siparis_detay` (
  `id` int(11) NOT NULL,
  `siparis_id` int(11) NOT NULL,
  `urun_id` int(11) NOT NULL,
  `miktar` int(11) NOT NULL,
  `birim_fiyat` decimal(10,2) NOT NULL,
  `toplam_fiyat` decimal(10,2) GENERATED ALWAYS AS ((`miktar` * `birim_fiyat`)) STORED,
  `notlar` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `siparis_log`
--

CREATE TABLE `siparis_log` (
  `id` int(11) NOT NULL,
  `siparis_id` int(11) NOT NULL,
  `islem_tipi` enum('olusturma','guncelleme','durum_degisiklik','iptal') NOT NULL,
  `aciklama` text,
  `kullanici_id` int(11) DEFAULT NULL,
  `tarih` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `sistem_ayarlari`
--

CREATE TABLE `sistem_ayarlari` (
  `id` int(11) NOT NULL,
  `anahtar` varchar(50) NOT NULL,
  `deger` text,
  `aciklama` varchar(255) DEFAULT NULL,
  `guncelleme_tarihi` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `sms_dogrulama_log`
--

CREATE TABLE `sms_dogrulama_log` (
  `id` int(11) NOT NULL,
  `kullanici_id` int(11) NOT NULL,
  `kullanici_tipi` enum('admin','personel') NOT NULL,
  `telefon` varchar(20) NOT NULL,
  `dogrulama_kodu` varchar(10) NOT NULL,
  `gonderim_tarihi` datetime DEFAULT CURRENT_TIMESTAMP,
  `dogrulama_tarihi` datetime DEFAULT NULL,
  `durum` enum('beklemede','dogrulandi','suresi_doldu','basarisiz') DEFAULT 'beklemede',
  `ip_adresi` varchar(45) DEFAULT NULL,
  `detay` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='SMS doğrulama işlemleri log tablosu';

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `sms_log`
--

CREATE TABLE `sms_log` (
  `id` int(11) NOT NULL,
  `telefon` varchar(20) NOT NULL,
  `mesaj` text NOT NULL,
  `yanit` varchar(255) DEFAULT NULL,
  `tarih` datetime DEFAULT CURRENT_TIMESTAMP,
  `kullanici_id` int(11) DEFAULT NULL,
  `kullanici_tipi` enum('admin','personel') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='SMS log tablosu - Hem admin hem personel tarafından kullanılabilir';

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `stock_reservations`
--

CREATE TABLE `stock_reservations` (
  `id` int(11) NOT NULL,
  `urun_id` int(11) NOT NULL,
  `magaza_id` int(11) NOT NULL,
  `reserved_amount` int(11) NOT NULL,
  `session_id` varchar(100) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  `status` enum('active','confirmed','expired','cancelled') DEFAULT 'active',
  `transaction_id` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Stok rezervasyon sistemi - çakışma koruması';

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `stok_hareketleri`
--

CREATE TABLE `stok_hareketleri` (
  `id` int(11) NOT NULL,
  `urun_id` int(11) NOT NULL,
  `miktar` int(11) NOT NULL,
  `hareket_tipi` enum('giris','cikis') NOT NULL,
  `aciklama` varchar(255) DEFAULT NULL,
  `belge_no` varchar(50) DEFAULT NULL,
  `tarih` datetime NOT NULL,
  `kullanici_id` int(11) DEFAULT NULL,
  `magaza_id` int(11) DEFAULT NULL,
  `depo_id` int(11) DEFAULT NULL,
  `maliyet` decimal(10,2) DEFAULT NULL,
  `satis_fiyati` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `store_config`
--

CREATE TABLE `store_config` (
  `id` int(11) NOT NULL,
  `magaza_id` int(11) NOT NULL,
  `config_key` varchar(50) NOT NULL,
  `config_value` text,
  `data_type` enum('string','integer','float','boolean','json') DEFAULT 'string',
  `is_synced` tinyint(1) DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Mağaza özel konfigürasyonları';

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `sync_metadata`
--

CREATE TABLE `sync_metadata` (
  `id` int(11) NOT NULL,
  `magaza_id` int(11) DEFAULT NULL,
  `tablo_adi` varchar(50) DEFAULT NULL,
  `son_sync_tarihi` datetime DEFAULT NULL,
  `sync_durumu` enum('basarili','hata') DEFAULT 'basarili',
  `operation_count` int(11) DEFAULT '0',
  `last_error` text,
  `sync_version` varchar(20) DEFAULT '1.0'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `sync_queue`
--

CREATE TABLE `sync_queue` (
  `id` int(11) NOT NULL,
  `magaza_id` int(11) NOT NULL,
  `operation_type` enum('sale','stock_update','customer_update','price_update') NOT NULL,
  `table_name` varchar(50) NOT NULL,
  `record_id` int(11) DEFAULT NULL,
  `data_json` text NOT NULL,
  `priority` int(3) DEFAULT '5',
  `attempts` int(3) DEFAULT '0',
  `max_attempts` int(3) DEFAULT '3',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `scheduled_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `processed_at` datetime DEFAULT NULL,
  `status` enum('pending','processing','completed','failed','cancelled') DEFAULT 'pending',
  `error_message` text,
  `sync_hash` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Senkronizasyon kuyruğu - offline ve bekleyen işlemler';

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `sync_stats`
--

CREATE TABLE `sync_stats` (
  `id` int(11) NOT NULL,
  `magaza_id` int(11) NOT NULL,
  `stat_date` date NOT NULL,
  `total_operations` int(11) DEFAULT '0',
  `successful_operations` int(11) DEFAULT '0',
  `failed_operations` int(11) DEFAULT '0',
  `avg_sync_time` float DEFAULT '0',
  `last_sync_time` datetime DEFAULT NULL,
  `data_volume_mb` float DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Günlük senkronizasyon istatistikleri';

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `tedarikciler`
--

CREATE TABLE `tedarikciler` (
  `id` int(11) NOT NULL,
  `ad` varchar(100) DEFAULT NULL,
  `telefon` varchar(20) DEFAULT NULL,
  `adres` varchar(255) DEFAULT NULL,
  `sehir` varchar(50) DEFAULT NULL,
  `eposta` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `urun_fiyat_gecmisi`
--

CREATE TABLE `urun_fiyat_gecmisi` (
  `id` int(11) NOT NULL,
  `urun_id` int(11) NOT NULL,
  `islem_tipi` enum('alis','satis_fiyati_guncelleme') NOT NULL,
  `eski_fiyat` decimal(10,2) DEFAULT NULL,
  `yeni_fiyat` decimal(10,2) NOT NULL,
  `fatura_id` int(11) DEFAULT NULL,
  `aciklama` text,
  `tarih` datetime DEFAULT CURRENT_TIMESTAMP,
  `kullanici_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `urun_onerileri`
--

CREATE TABLE `urun_onerileri` (
  `id` int(11) NOT NULL,
  `barkod` varchar(50) DEFAULT NULL,
  `ad` varchar(100) DEFAULT NULL,
  `onerilen_tarih` datetime DEFAULT CURRENT_TIMESTAMP,
  `durum` enum('beklemede','eklendi','reddedildi') DEFAULT 'beklemede',
  `kullanici_id` int(11) DEFAULT NULL,
  `notlar` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `urun_stok`
--

CREATE TABLE `urun_stok` (
  `id` int(11) NOT NULL,
  `kod` varchar(50) DEFAULT NULL,
  `barkod` varchar(50) DEFAULT NULL,
  `ad` varchar(100) DEFAULT NULL,
  `web_id` varchar(50) DEFAULT NULL,
  `yil` int(11) DEFAULT NULL,
  `kdv_orani` decimal(5,2) DEFAULT NULL,
  `satis_fiyati` decimal(10,2) DEFAULT NULL,
  `alis_fiyati` decimal(10,2) DEFAULT NULL,
  `indirimli_fiyat` decimal(10,2) DEFAULT NULL,
  `stok_miktari` int(11) DEFAULT NULL,
  `kayit_tarihi` date DEFAULT NULL,
  `resim_yolu` varchar(255) DEFAULT NULL,
  `indirim_baslangic_tarihi` date DEFAULT NULL,
  `indirim_bitis_tarihi` date DEFAULT NULL,
  `durum` enum('aktif','pasif') DEFAULT 'aktif',
  `departman_id` int(11) DEFAULT NULL,
  `birim_id` int(11) DEFAULT NULL,
  `ana_grup_id` int(11) DEFAULT NULL,
  `alt_grup_id` int(11) DEFAULT NULL,
  `last_updated` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dökümü yapılmış tablolar için indeksler
--

--
-- Tablo için indeksler `admin_user`
--
ALTER TABLE `admin_user`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `kullanici_adi` (`kullanici_adi`);

--
-- Tablo için indeksler `alis_faturalari`
--
ALTER TABLE `alis_faturalari`
  ADD PRIMARY KEY (`id`),
  ADD KEY `magaza` (`magaza`),
  ADD KEY `tedarikci` (`tedarikci`);

--
-- Tablo için indeksler `alis_fatura_aktarim`
--
ALTER TABLE `alis_fatura_aktarim`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fatura_id` (`fatura_id`),
  ADD KEY `magaza_id` (`magaza_id`),
  ADD KEY `depo_id` (`depo_id`);

--
-- Tablo için indeksler `alis_fatura_detay`
--
ALTER TABLE `alis_fatura_detay`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fatura_id` (`fatura_id`),
  ADD KEY `urun_id` (`urun_id`);

--
-- Tablo için indeksler `alis_fatura_detay_aktarim`
--
ALTER TABLE `alis_fatura_detay_aktarim`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fatura_id` (`fatura_id`),
  ADD KEY `urun_id` (`urun_id`),
  ADD KEY `magaza_id` (`magaza_id`);

--
-- Tablo için indeksler `alis_fatura_log`
--
ALTER TABLE `alis_fatura_log`
  ADD PRIMARY KEY (`id`);

--
-- Tablo için indeksler `alt_gruplar`
--
ALTER TABLE `alt_gruplar`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_alt_grup` (`ad`,`ana_grup_id`),
  ADD KEY `ana_grup_id` (`ana_grup_id`);

--
-- Tablo için indeksler `ana_gruplar`
--
ALTER TABLE `ana_gruplar`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ad` (`ad`),
  ADD KEY `departman_id` (`departman_id`);

--
-- Tablo için indeksler `barcode_log`
--
ALTER TABLE `barcode_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `urun_id` (`urun_id`),
  ADD KEY `kullanici_id` (`kullanici_id`);

--
-- Tablo için indeksler `barcode_settings`
--
ALTER TABLE `barcode_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `anahtar` (`anahtar`);

--
-- Tablo için indeksler `birimler`
--
ALTER TABLE `birimler`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ad` (`ad`);

--
-- Tablo için indeksler `conflict_log`
--
ALTER TABLE `conflict_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_magaza_type` (`magaza_id`,`conflict_type`),
  ADD KEY `idx_resolution` (`resolution_type`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `urun_id` (`urun_id`);

--
-- Tablo için indeksler `departmanlar`
--
ALTER TABLE `departmanlar`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ad` (`ad`);

--
-- Tablo için indeksler `depolar`
--
ALTER TABLE `depolar`
  ADD PRIMARY KEY (`id`);

--
-- Tablo için indeksler `depo_stok`
--
ALTER TABLE `depo_stok`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `depo_urun_unique` (`depo_id`,`urun_id`),
  ADD KEY `urun_id` (`urun_id`);

--
-- Tablo için indeksler `fatura_erisim_token`
--
ALTER TABLE `fatura_erisim_token`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `fatura_id` (`fatura_id`);

--
-- Tablo için indeksler `fiyat_gor`
--
ALTER TABLE `fiyat_gor`
  ADD PRIMARY KEY (`barkod`);

--
-- Tablo için indeksler `iletisim_log`
--
ALTER TABLE `iletisim_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fatura_id` (`fatura_id`),
  ADD KEY `musteri_id` (`musteri_id`);

--
-- Tablo için indeksler `incibot_log`
--
ALTER TABLE `incibot_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `kullanici_id` (`kullanici_id`);

--
-- Tablo için indeksler `indirimler`
--
ALTER TABLE `indirimler`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_indirimler_kullanici` (`kullanici_id`);

--
-- Tablo için indeksler `indirim_detay`
--
ALTER TABLE `indirim_detay`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_indirim_detay_indirim` (`indirim_id`),
  ADD KEY `fk_indirim_detay_urun` (`urun_id`);

--
-- Tablo için indeksler `invoice_sequences`
--
ALTER TABLE `invoice_sequences`
  ADD PRIMARY KEY (`magaza_id`,`sequence_date`);

--
-- Tablo için indeksler `kullanici_ban`
--
ALTER TABLE `kullanici_ban`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_kullanici_id` (`kullanici_id`),
  ADD KEY `idx_kullanici_tipi` (`kullanici_tipi`),
  ADD KEY `idx_ban_bitis` (`ban_bitis`);

--
-- Tablo için indeksler `kullanici_giris_log`
--
ALTER TABLE `kullanici_giris_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_kullanici_id` (`kullanici_id`),
  ADD KEY `idx_kullanici_tipi` (`kullanici_tipi`),
  ADD KEY `idx_islem_tipi` (`islem_tipi`),
  ADD KEY `idx_tarih` (`tarih`);

--
-- Tablo için indeksler `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ip_adresi` (`ip_adresi`),
  ADD KEY `idx_zaman` (`zaman`);

--
-- Tablo için indeksler `login_ban`
--
ALTER TABLE `login_ban`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ip_adresi` (`ip_adresi`),
  ADD KEY `idx_ban_bitis` (`ban_bitis`);

--
-- Tablo için indeksler `magazalar`
--
ALTER TABLE `magazalar`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id` (`id`);

--
-- Tablo için indeksler `magaza_stok`
--
ALTER TABLE `magaza_stok`
  ADD PRIMARY KEY (`id`),
  ADD KEY `barkod` (`barkod`),
  ADD KEY `magaza_id` (`magaza_id`);

--
-- Tablo için indeksler `musteriler`
--
ALTER TABLE `musteriler`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `telefon` (`telefon`),
  ADD UNIQUE KEY `barkod` (`barkod`);

--
-- Tablo için indeksler `musteri_borclar`
--
ALTER TABLE `musteri_borclar`
  ADD PRIMARY KEY (`borc_id`),
  ADD KEY `musteri_id` (`musteri_id`),
  ADD KEY `magaza_id` (`magaza_id`);

--
-- Tablo için indeksler `musteri_borc_detaylar`
--
ALTER TABLE `musteri_borc_detaylar`
  ADD PRIMARY KEY (`detay_id`),
  ADD KEY `borc_id` (`borc_id`),
  ADD KEY `urun_id` (`urun_id`);

--
-- Tablo için indeksler `musteri_borc_odemeler`
--
ALTER TABLE `musteri_borc_odemeler`
  ADD PRIMARY KEY (`odeme_id`),
  ADD KEY `borc_id` (`borc_id`),
  ADD KEY `kullanici_id` (`kullanici_id`);

--
-- Tablo için indeksler `musteri_puanlar`
--
ALTER TABLE `musteri_puanlar`
  ADD PRIMARY KEY (`id`),
  ADD KEY `musteri_id` (`musteri_id`);

--
-- Tablo için indeksler `offline_sales`
--
ALTER TABLE `offline_sales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_local_invoice` (`magaza_id`,`local_invoice_id`),
  ADD KEY `idx_magaza_status` (`magaza_id`,`status`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_checksum` (`checksum`),
  ADD KEY `synced_invoice_id` (`synced_invoice_id`);

--
-- Tablo için indeksler `personel`
--
ALTER TABLE `personel`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `kullanici_adi` (`kullanici_adi`),
  ADD KEY `magaza_id` (`magaza_id`);

--
-- Tablo için indeksler `puan_ayarlari`
--
ALTER TABLE `puan_ayarlari`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `musteri_turu` (`musteri_turu`);

--
-- Tablo için indeksler `puan_harcama`
--
ALTER TABLE `puan_harcama`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fatura_id` (`fatura_id`),
  ADD KEY `musteri_id` (`musteri_id`);

--
-- Tablo için indeksler `puan_kazanma`
--
ALTER TABLE `puan_kazanma`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fatura_id` (`fatura_id`),
  ADD KEY `musteri_id` (`musteri_id`);

--
-- Tablo için indeksler `satis_faturalari`
--
ALTER TABLE `satis_faturalari`
  ADD PRIMARY KEY (`id`),
  ADD KEY `magaza` (`magaza`),
  ADD KEY `personel` (`personel`),
  ADD KEY `fk_satis_faturalari_musteri` (`musteri_id`),
  ADD KEY `fk_iliskili_fatura` (`iliskili_fatura_id`);

--
-- Tablo için indeksler `satis_fatura_detay`
--
ALTER TABLE `satis_fatura_detay`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fatura_id` (`fatura_id`),
  ADD KEY `urun_id` (`urun_id`);

--
-- Tablo için indeksler `siparisler`
--
ALTER TABLE `siparisler`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tedarikci_id` (`tedarikci_id`),
  ADD KEY `kullanici_id` (`kullanici_id`);

--
-- Tablo için indeksler `siparis_detay`
--
ALTER TABLE `siparis_detay`
  ADD PRIMARY KEY (`id`),
  ADD KEY `siparis_id` (`siparis_id`),
  ADD KEY `urun_id` (`urun_id`);

--
-- Tablo için indeksler `siparis_log`
--
ALTER TABLE `siparis_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `siparis_id` (`siparis_id`),
  ADD KEY `kullanici_id` (`kullanici_id`);

--
-- Tablo için indeksler `sistem_ayarlari`
--
ALTER TABLE `sistem_ayarlari`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `anahtar` (`anahtar`);

--
-- Tablo için indeksler `sms_dogrulama_log`
--
ALTER TABLE `sms_dogrulama_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_kullanici_id` (`kullanici_id`),
  ADD KEY `idx_kullanici_tipi` (`kullanici_tipi`),
  ADD KEY `idx_telefon` (`telefon`),
  ADD KEY `idx_gonderim_tarihi` (`gonderim_tarihi`);

--
-- Tablo için indeksler `sms_log`
--
ALTER TABLE `sms_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `kullanici_id` (`kullanici_id`);

--
-- Tablo için indeksler `stock_reservations`
--
ALTER TABLE `stock_reservations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_urun_magaza` (`urun_id`,`magaza_id`),
  ADD KEY `idx_session` (`session_id`),
  ADD KEY `idx_expires` (`expires_at`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `magaza_id` (`magaza_id`);

--
-- Tablo için indeksler `stok_hareketleri`
--
ALTER TABLE `stok_hareketleri`
  ADD PRIMARY KEY (`id`),
  ADD KEY `urun_id` (`urun_id`),
  ADD KEY `kullanici_id` (`kullanici_id`),
  ADD KEY `magaza_id` (`magaza_id`),
  ADD KEY `depo_id` (`depo_id`);

--
-- Tablo için indeksler `store_config`
--
ALTER TABLE `store_config`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_magaza_config` (`magaza_id`,`config_key`),
  ADD KEY `idx_config_key` (`config_key`);

--
-- Tablo için indeksler `sync_metadata`
--
ALTER TABLE `sync_metadata`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `magaza_id` (`magaza_id`,`tablo_adi`);

--
-- Tablo için indeksler `sync_queue`
--
ALTER TABLE `sync_queue`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_magaza_status` (`magaza_id`,`status`),
  ADD KEY `idx_scheduled` (`scheduled_at`),
  ADD KEY `idx_priority` (`priority`),
  ADD KEY `idx_operation` (`operation_type`),
  ADD KEY `idx_sync_hash` (`sync_hash`);

--
-- Tablo için indeksler `sync_stats`
--
ALTER TABLE `sync_stats`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_magaza_date` (`magaza_id`,`stat_date`),
  ADD KEY `idx_stat_date` (`stat_date`);

--
-- Tablo için indeksler `tedarikciler`
--
ALTER TABLE `tedarikciler`
  ADD PRIMARY KEY (`id`);

--
-- Tablo için indeksler `urun_fiyat_gecmisi`
--
ALTER TABLE `urun_fiyat_gecmisi`
  ADD PRIMARY KEY (`id`),
  ADD KEY `urun_id` (`urun_id`),
  ADD KEY `fatura_id` (`fatura_id`),
  ADD KEY `kullanici_id` (`kullanici_id`);

--
-- Tablo için indeksler `urun_onerileri`
--
ALTER TABLE `urun_onerileri`
  ADD PRIMARY KEY (`id`),
  ADD KEY `kullanici_id` (`kullanici_id`);

--
-- Tablo için indeksler `urun_stok`
--
ALTER TABLE `urun_stok`
  ADD PRIMARY KEY (`id`),
  ADD KEY `barkod` (`barkod`),
  ADD KEY `departman_id` (`departman_id`),
  ADD KEY `birim_id` (`birim_id`),
  ADD KEY `ana_grup_id` (`ana_grup_id`),
  ADD KEY `alt_grup_id` (`alt_grup_id`);

--
-- Dökümü yapılmış tablolar için AUTO_INCREMENT değeri
--

--
-- Tablo için AUTO_INCREMENT değeri `admin_user`
--
ALTER TABLE `admin_user`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `alis_faturalari`
--
ALTER TABLE `alis_faturalari`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `alis_fatura_aktarim`
--
ALTER TABLE `alis_fatura_aktarim`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `alis_fatura_detay`
--
ALTER TABLE `alis_fatura_detay`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `alis_fatura_detay_aktarim`
--
ALTER TABLE `alis_fatura_detay_aktarim`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `alis_fatura_log`
--
ALTER TABLE `alis_fatura_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `alt_gruplar`
--
ALTER TABLE `alt_gruplar`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `ana_gruplar`
--
ALTER TABLE `ana_gruplar`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `barcode_log`
--
ALTER TABLE `barcode_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `barcode_settings`
--
ALTER TABLE `barcode_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `birimler`
--
ALTER TABLE `birimler`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `conflict_log`
--
ALTER TABLE `conflict_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `departmanlar`
--
ALTER TABLE `departmanlar`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `depolar`
--
ALTER TABLE `depolar`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `depo_stok`
--
ALTER TABLE `depo_stok`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `fatura_erisim_token`
--
ALTER TABLE `fatura_erisim_token`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `iletisim_log`
--
ALTER TABLE `iletisim_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `incibot_log`
--
ALTER TABLE `incibot_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `indirimler`
--
ALTER TABLE `indirimler`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `indirim_detay`
--
ALTER TABLE `indirim_detay`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `kullanici_ban`
--
ALTER TABLE `kullanici_ban`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `kullanici_giris_log`
--
ALTER TABLE `kullanici_giris_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `login_ban`
--
ALTER TABLE `login_ban`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `magazalar`
--
ALTER TABLE `magazalar`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `magaza_stok`
--
ALTER TABLE `magaza_stok`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `musteriler`
--
ALTER TABLE `musteriler`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `musteri_borclar`
--
ALTER TABLE `musteri_borclar`
  MODIFY `borc_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `musteri_borc_detaylar`
--
ALTER TABLE `musteri_borc_detaylar`
  MODIFY `detay_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `musteri_borc_odemeler`
--
ALTER TABLE `musteri_borc_odemeler`
  MODIFY `odeme_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `musteri_puanlar`
--
ALTER TABLE `musteri_puanlar`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `offline_sales`
--
ALTER TABLE `offline_sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `personel`
--
ALTER TABLE `personel`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `puan_ayarlari`
--
ALTER TABLE `puan_ayarlari`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `puan_harcama`
--
ALTER TABLE `puan_harcama`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `puan_kazanma`
--
ALTER TABLE `puan_kazanma`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `satis_faturalari`
--
ALTER TABLE `satis_faturalari`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `satis_fatura_detay`
--
ALTER TABLE `satis_fatura_detay`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `siparisler`
--
ALTER TABLE `siparisler`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `siparis_detay`
--
ALTER TABLE `siparis_detay`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `siparis_log`
--
ALTER TABLE `siparis_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `sistem_ayarlari`
--
ALTER TABLE `sistem_ayarlari`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `sms_dogrulama_log`
--
ALTER TABLE `sms_dogrulama_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `sms_log`
--
ALTER TABLE `sms_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `stock_reservations`
--
ALTER TABLE `stock_reservations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `stok_hareketleri`
--
ALTER TABLE `stok_hareketleri`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `store_config`
--
ALTER TABLE `store_config`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `sync_metadata`
--
ALTER TABLE `sync_metadata`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `sync_queue`
--
ALTER TABLE `sync_queue`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `sync_stats`
--
ALTER TABLE `sync_stats`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `tedarikciler`
--
ALTER TABLE `tedarikciler`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `urun_fiyat_gecmisi`
--
ALTER TABLE `urun_fiyat_gecmisi`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `urun_onerileri`
--
ALTER TABLE `urun_onerileri`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `urun_stok`
--
ALTER TABLE `urun_stok`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Dökümü yapılmış tablolar için kısıtlamalar
--

--
-- Tablo kısıtlamaları `alis_faturalari`
--
ALTER TABLE `alis_faturalari`
  ADD CONSTRAINT `alis_faturalari_ibfk_1` FOREIGN KEY (`magaza`) REFERENCES `magazalar` (`id`),
  ADD CONSTRAINT `alis_faturalari_ibfk_2` FOREIGN KEY (`tedarikci`) REFERENCES `tedarikciler` (`id`);

--
-- Tablo kısıtlamaları `alis_fatura_aktarim`
--
ALTER TABLE `alis_fatura_aktarim`
  ADD CONSTRAINT `alis_fatura_aktarim_ibfk_1` FOREIGN KEY (`fatura_id`) REFERENCES `alis_faturalari` (`id`),
  ADD CONSTRAINT `alis_fatura_aktarim_ibfk_2` FOREIGN KEY (`magaza_id`) REFERENCES `magazalar` (`id`),
  ADD CONSTRAINT `alis_fatura_aktarim_ibfk_3` FOREIGN KEY (`depo_id`) REFERENCES `depolar` (`id`);

--
-- Tablo kısıtlamaları `alis_fatura_detay`
--
ALTER TABLE `alis_fatura_detay`
  ADD CONSTRAINT `alis_fatura_detay_ibfk_1` FOREIGN KEY (`fatura_id`) REFERENCES `alis_faturalari` (`id`),
  ADD CONSTRAINT `alis_fatura_detay_ibfk_2` FOREIGN KEY (`urun_id`) REFERENCES `urun_stok` (`id`);

--
-- Tablo kısıtlamaları `alis_fatura_detay_aktarim`
--
ALTER TABLE `alis_fatura_detay_aktarim`
  ADD CONSTRAINT `alis_fatura_detay_aktarim_ibfk_1` FOREIGN KEY (`fatura_id`) REFERENCES `alis_faturalari` (`id`),
  ADD CONSTRAINT `alis_fatura_detay_aktarim_ibfk_2` FOREIGN KEY (`urun_id`) REFERENCES `urun_stok` (`id`),
  ADD CONSTRAINT `alis_fatura_detay_aktarim_ibfk_3` FOREIGN KEY (`magaza_id`) REFERENCES `magazalar` (`id`);

--
-- Tablo kısıtlamaları `alt_gruplar`
--
ALTER TABLE `alt_gruplar`
  ADD CONSTRAINT `alt_gruplar_ibfk_1` FOREIGN KEY (`ana_grup_id`) REFERENCES `ana_gruplar` (`id`) ON DELETE CASCADE;

--
-- Tablo kısıtlamaları `ana_gruplar`
--
ALTER TABLE `ana_gruplar`
  ADD CONSTRAINT `ana_gruplar_ibfk_1` FOREIGN KEY (`departman_id`) REFERENCES `departmanlar` (`id`) ON DELETE SET NULL;

--
-- Tablo kısıtlamaları `barcode_log`
--
ALTER TABLE `barcode_log`
  ADD CONSTRAINT `barcode_log_ibfk_1` FOREIGN KEY (`urun_id`) REFERENCES `urun_stok` (`id`) ON DELETE CASCADE;

--
-- Tablo kısıtlamaları `conflict_log`
--
ALTER TABLE `conflict_log`
  ADD CONSTRAINT `conflict_log_ibfk_1` FOREIGN KEY (`magaza_id`) REFERENCES `magazalar` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `conflict_log_ibfk_2` FOREIGN KEY (`urun_id`) REFERENCES `urun_stok` (`id`) ON DELETE SET NULL;

--
-- Tablo kısıtlamaları `depo_stok`
--
ALTER TABLE `depo_stok`
  ADD CONSTRAINT `depo_stok_ibfk_1` FOREIGN KEY (`depo_id`) REFERENCES `depolar` (`id`),
  ADD CONSTRAINT `depo_stok_ibfk_2` FOREIGN KEY (`urun_id`) REFERENCES `urun_stok` (`id`);

--
-- Tablo kısıtlamaları `indirimler`
--
ALTER TABLE `indirimler`
  ADD CONSTRAINT `fk_indirimler_kullanici` FOREIGN KEY (`kullanici_id`) REFERENCES `admin_user` (`id`);

--
-- Tablo kısıtlamaları `indirim_detay`
--
ALTER TABLE `indirim_detay`
  ADD CONSTRAINT `fk_indirim_detay_indirim` FOREIGN KEY (`indirim_id`) REFERENCES `indirimler` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_indirim_detay_urun` FOREIGN KEY (`urun_id`) REFERENCES `urun_stok` (`id`) ON DELETE CASCADE;

--
-- Tablo kısıtlamaları `invoice_sequences`
--
ALTER TABLE `invoice_sequences`
  ADD CONSTRAINT `invoice_sequences_ibfk_1` FOREIGN KEY (`magaza_id`) REFERENCES `magazalar` (`id`) ON DELETE CASCADE;

--
-- Tablo kısıtlamaları `magaza_stok`
--
ALTER TABLE `magaza_stok`
  ADD CONSTRAINT `magaza_stok_ibfk_1` FOREIGN KEY (`barkod`) REFERENCES `urun_stok` (`barkod`),
  ADD CONSTRAINT `magaza_stok_ibfk_2` FOREIGN KEY (`magaza_id`) REFERENCES `magazalar` (`id`);

--
-- Tablo kısıtlamaları `musteri_borclar`
--
ALTER TABLE `musteri_borclar`
  ADD CONSTRAINT `musteri_borclar_ibfk_1` FOREIGN KEY (`musteri_id`) REFERENCES `musteriler` (`id`),
  ADD CONSTRAINT `musteri_borclar_ibfk_2` FOREIGN KEY (`magaza_id`) REFERENCES `magazalar` (`id`);

--
-- Tablo kısıtlamaları `musteri_borc_detaylar`
--
ALTER TABLE `musteri_borc_detaylar`
  ADD CONSTRAINT `musteri_borc_detaylar_ibfk_1` FOREIGN KEY (`borc_id`) REFERENCES `musteri_borclar` (`borc_id`),
  ADD CONSTRAINT `musteri_borc_detaylar_ibfk_2` FOREIGN KEY (`urun_id`) REFERENCES `urun_stok` (`id`);

--
-- Tablo kısıtlamaları `musteri_borc_odemeler`
--
ALTER TABLE `musteri_borc_odemeler`
  ADD CONSTRAINT `musteri_borc_odemeler_ibfk_1` FOREIGN KEY (`borc_id`) REFERENCES `musteri_borclar` (`borc_id`),
  ADD CONSTRAINT `musteri_borc_odemeler_ibfk_2` FOREIGN KEY (`kullanici_id`) REFERENCES `personel` (`id`);

--
-- Tablo kısıtlamaları `offline_sales`
--
ALTER TABLE `offline_sales`
  ADD CONSTRAINT `offline_sales_ibfk_1` FOREIGN KEY (`magaza_id`) REFERENCES `magazalar` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `offline_sales_ibfk_2` FOREIGN KEY (`synced_invoice_id`) REFERENCES `satis_faturalari` (`id`) ON DELETE SET NULL;

--
-- Tablo kısıtlamaları `personel`
--
ALTER TABLE `personel`
  ADD CONSTRAINT `personel_ibfk_1` FOREIGN KEY (`magaza_id`) REFERENCES `magazalar` (`id`);

--
-- Tablo kısıtlamaları `puan_harcama`
--
ALTER TABLE `puan_harcama`
  ADD CONSTRAINT `fk_puan_harcama_musteri` FOREIGN KEY (`musteri_id`) REFERENCES `musteriler` (`id`) ON DELETE CASCADE;

--
-- Tablo kısıtlamaları `puan_kazanma`
--
ALTER TABLE `puan_kazanma`
  ADD CONSTRAINT `fk_puan_kazanma_musteri` FOREIGN KEY (`musteri_id`) REFERENCES `musteriler` (`id`) ON DELETE CASCADE;

--
-- Tablo kısıtlamaları `satis_faturalari`
--
ALTER TABLE `satis_faturalari`
  ADD CONSTRAINT `fk_iliskili_fatura` FOREIGN KEY (`iliskili_fatura_id`) REFERENCES `satis_faturalari` (`id`),
  ADD CONSTRAINT `fk_satis_faturalari_musteri` FOREIGN KEY (`musteri_id`) REFERENCES `musteriler` (`id`),
  ADD CONSTRAINT `satis_faturalari_ibfk_1` FOREIGN KEY (`magaza`) REFERENCES `magazalar` (`id`),
  ADD CONSTRAINT `satis_faturalari_ibfk_2` FOREIGN KEY (`personel`) REFERENCES `personel` (`id`);

--
-- Tablo kısıtlamaları `satis_fatura_detay`
--
ALTER TABLE `satis_fatura_detay`
  ADD CONSTRAINT `satis_fatura_detay_ibfk_1` FOREIGN KEY (`fatura_id`) REFERENCES `satis_faturalari` (`id`),
  ADD CONSTRAINT `satis_fatura_detay_ibfk_2` FOREIGN KEY (`urun_id`) REFERENCES `urun_stok` (`id`);

--
-- Tablo kısıtlamaları `siparisler`
--
ALTER TABLE `siparisler`
  ADD CONSTRAINT `siparisler_ibfk_1` FOREIGN KEY (`tedarikci_id`) REFERENCES `tedarikciler` (`id`),
  ADD CONSTRAINT `siparisler_ibfk_2` FOREIGN KEY (`kullanici_id`) REFERENCES `admin_user` (`id`);

--
-- Tablo kısıtlamaları `siparis_detay`
--
ALTER TABLE `siparis_detay`
  ADD CONSTRAINT `siparis_detay_ibfk_1` FOREIGN KEY (`siparis_id`) REFERENCES `siparisler` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `siparis_detay_ibfk_2` FOREIGN KEY (`urun_id`) REFERENCES `urun_stok` (`id`);

--
-- Tablo kısıtlamaları `siparis_log`
--
ALTER TABLE `siparis_log`
  ADD CONSTRAINT `siparis_log_ibfk_1` FOREIGN KEY (`siparis_id`) REFERENCES `siparisler` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `siparis_log_ibfk_2` FOREIGN KEY (`kullanici_id`) REFERENCES `admin_user` (`id`);

--
-- Tablo kısıtlamaları `stock_reservations`
--
ALTER TABLE `stock_reservations`
  ADD CONSTRAINT `stock_reservations_ibfk_1` FOREIGN KEY (`urun_id`) REFERENCES `urun_stok` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `stock_reservations_ibfk_2` FOREIGN KEY (`magaza_id`) REFERENCES `magazalar` (`id`) ON DELETE CASCADE;

--
-- Tablo kısıtlamaları `stok_hareketleri`
--
ALTER TABLE `stok_hareketleri`
  ADD CONSTRAINT `stok_hareketleri_ibfk_1` FOREIGN KEY (`urun_id`) REFERENCES `urun_stok` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `stok_hareketleri_ibfk_2` FOREIGN KEY (`kullanici_id`) REFERENCES `admin_user` (`id`),
  ADD CONSTRAINT `stok_hareketleri_ibfk_3` FOREIGN KEY (`magaza_id`) REFERENCES `magazalar` (`id`),
  ADD CONSTRAINT `stok_hareketleri_ibfk_4` FOREIGN KEY (`depo_id`) REFERENCES `depolar` (`id`);

--
-- Tablo kısıtlamaları `store_config`
--
ALTER TABLE `store_config`
  ADD CONSTRAINT `store_config_ibfk_1` FOREIGN KEY (`magaza_id`) REFERENCES `magazalar` (`id`) ON DELETE CASCADE;

--
-- Tablo kısıtlamaları `sync_queue`
--
ALTER TABLE `sync_queue`
  ADD CONSTRAINT `sync_queue_ibfk_1` FOREIGN KEY (`magaza_id`) REFERENCES `magazalar` (`id`) ON DELETE CASCADE;

--
-- Tablo kısıtlamaları `sync_stats`
--
ALTER TABLE `sync_stats`
  ADD CONSTRAINT `sync_stats_ibfk_1` FOREIGN KEY (`magaza_id`) REFERENCES `magazalar` (`id`) ON DELETE CASCADE;

--
-- Tablo kısıtlamaları `urun_fiyat_gecmisi`
--
ALTER TABLE `urun_fiyat_gecmisi`
  ADD CONSTRAINT `urun_fiyat_gecmisi_ibfk_1` FOREIGN KEY (`urun_id`) REFERENCES `urun_stok` (`id`),
  ADD CONSTRAINT `urun_fiyat_gecmisi_ibfk_2` FOREIGN KEY (`fatura_id`) REFERENCES `alis_faturalari` (`id`),
  ADD CONSTRAINT `urun_fiyat_gecmisi_ibfk_3` FOREIGN KEY (`kullanici_id`) REFERENCES `admin_user` (`id`);

--
-- Tablo kısıtlamaları `urun_onerileri`
--
ALTER TABLE `urun_onerileri`
  ADD CONSTRAINT `urun_onerileri_ibfk_1` FOREIGN KEY (`kullanici_id`) REFERENCES `admin_user` (`id`);

--
-- Tablo kısıtlamaları `urun_stok`
--
ALTER TABLE `urun_stok`
  ADD CONSTRAINT `urun_stok_ibfk_1` FOREIGN KEY (`departman_id`) REFERENCES `departmanlar` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `urun_stok_ibfk_2` FOREIGN KEY (`birim_id`) REFERENCES `birimler` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `urun_stok_ibfk_3` FOREIGN KEY (`ana_grup_id`) REFERENCES `ana_gruplar` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `urun_stok_ibfk_4` FOREIGN KEY (`alt_grup_id`) REFERENCES `alt_gruplar` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
