<?php
session_start();
date_default_timezone_set('Europe/Istanbul');

/* =========================================
   0. CIKIS ISLEMI (veritabanindan once)
========================================= */
if (isset($_GET['cikis'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $cookie['path'], $cookie['domain'],
            $cookie['secure'], $cookie['httponly']
        );
    }
    session_destroy();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    $anaSayfa = strtok($_SERVER['REQUEST_URI'] ?? 'index.php', '?');
    header('Location: ' . ($anaSayfa !== false && $anaSayfa !== '' ? $anaSayfa : 'index.php'), true, 303);
    exit;
}

/* =========================================
   1. AYARLAR
========================================= */

$host = 'localhost';
$dbname = 'randevu_sistemi';
$dbuser = 'root';
$dbpass = ''; // MySQL parolan varsa buraya yaz.

const VERI_SURUM = 'v3-tum-turkiye';

// Parola sıfırlama:
//  true  -> yerel/demo mod: e-posta gönderilmez, sıfırlama bağlantısı ekranda gösterilir.
//  false -> canlı mod: bağlantı e-postayla gönderilir (sunucuda mail() çalışmalı).
// Canlıya çıkarken MUTLAKA false yap ve SITE_URL'i kendi adresinle doldur (ör. https://siteadi.com/index.php).
const SIFIRLAMA_DEMO = true;
const SITE_URL = '';

/* =========================================
   2. VERI URETIMI (veritabanindan bagimsiz saf fonksiyonlar)
   Hastane ve doktor adlarinin tamami kurgusaldir.
========================================= */
// ==== VERI URETIMI BASLA ====

function iller81(): array
{
    // Plaka sirasi: dizideki sira + 1 = plaka kodu
    return [
        'Adana','Adıyaman','Afyonkarahisar','Ağrı','Amasya','Ankara','Antalya','Artvin','Aydın','Balıkesir',
        'Bilecik','Bingöl','Bitlis','Bolu','Burdur','Bursa','Çanakkale','Çankırı','Çorum','Denizli',
        'Diyarbakır','Edirne','Elazığ','Erzincan','Erzurum','Eskişehir','Gaziantep','Giresun','Gümüşhane','Hakkâri',
        'Hatay','Isparta','Mersin','İstanbul','İzmir','Kars','Kastamonu','Kayseri','Kırklareli','Kırşehir',
        'Kocaeli','Konya','Kütahya','Malatya','Manisa','Kahramanmaraş','Mardin','Muğla','Muş','Nevşehir',
        'Niğde','Ordu','Rize','Sakarya','Samsun','Siirt','Sinop','Sivas','Tekirdağ','Tokat',
        'Trabzon','Tunceli','Şanlıurfa','Uşak','Van','Yozgat','Zonguldak','Aksaray','Bayburt','Karaman',
        'Kırıkkale','Batman','Şırnak','Bartın','Ardahan','Iğdır','Yalova','Karabük','Kilis','Osmaniye','Düzce'
    ];
}

function poliklinikGruplari(): array
{
    $cekirdek = [
        'İç Hastalıkları (Dahiliye)', 'Çocuk Sağlığı ve Hastalıkları', 'Genel Cerrahi',
        'Kadın Hastalıkları ve Doğum', 'Ortopedi ve Travmatoloji', 'Kulak Burun Boğaz (KBB)',
        'Göz Hastalıkları', 'Dermatoloji (Cildiye)'
    ];
    $orta = array_merge($cekirdek, [
        'Kardiyoloji', 'Üroloji', 'Nöroloji', 'Göğüs Hastalıkları', 'Psikiyatri',
        'Fiziksel Tıp ve Rehabilitasyon', 'Diş Hekimliği'
    ]);
    $genis = array_merge($orta, [
        'Beyin ve Sinir Cerrahisi', 'Endokrinoloji ve Metabolizma', 'Gastroenteroloji',
        'Enfeksiyon Hastalıkları', 'Nefroloji', 'Kalp ve Damar Cerrahisi',
        'Plastik ve Estetik Cerrahi', 'Tıbbi Onkoloji', 'Romatoloji', 'Beslenme ve Diyet'
    ]);
    $tam = array_merge($genis, [
        'Hematoloji', 'Göğüs Cerrahisi', 'Çocuk Cerrahisi', 'Çocuk Kardiyolojisi',
        'Çocuk Nörolojisi', 'Çocuk Endokrinolojisi', 'Çocuk ve Ergen Ruh Sağlığı',
        'Alerji ve İmmünoloji', 'Geriatri', 'Radyasyon Onkolojisi', 'Ağrı (Algoloji)',
        'Tıbbi Genetik', 'Ağız, Diş ve Çene Cerrahisi', 'Ortodonti'
    ]);
    $dis = [
        'Diş Hekimliği', 'Ağız, Diş ve Çene Cerrahisi', 'Ortodonti', 'Pedodonti (Çocuk Diş)',
        'Endodonti', 'Periodontoloji', 'Protetik Diş Tedavisi'
    ];
    return ['cekirdek' => $cekirdek, 'orta' => $orta, 'genis' => $genis, 'tam' => $tam, 'dis' => $dis];
}

function hastaneTipEtiketi(string $tip): string
{
    $e = [
        'DH' => 'Devlet Hastanesi', 'ADSM' => 'Ağız ve Diş Sağlığı Merkezi',
        'EAH' => 'Eğitim ve Araştırma Hastanesi', 'SH' => 'Şehir Hastanesi',
        'UH' => 'Üniversite Hastanesi', 'OH' => 'Özel Hastane',
        'KDC' => 'Kadın Doğum ve Çocuk Hastanesi', 'RSH' => 'Ruh Sağlığı Hastanesi',
        'GGH' => 'Göğüs Hastalıkları Hastanesi'
    ];
    return $e[$tip] ?? $tip;
}

/* ---- Sikayetten poliklinik onerisi (kural motoru) ----
   Not: Tibbi teshis degildir. Ileride bu fonksiyon bir yapay zeka servisine baglanabilir;
   donus bicimi ayni kaldigi surece arayuz degismez. */
function belirtiNormallestir(string $m): string
{
    $m = mb_strtolower(strtr($m, ['I' => 'ı', 'İ' => 'i']), 'UTF-8');
    $m = strtr($m, ['ı' => 'i', 'ş' => 's', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c', 'â' => 'a', 'î' => 'i', 'û' => 'u']);
    $m = preg_replace('/[^a-z0-9\s]+/', ' ', $m);
    $m = preg_replace('/\s+/', ' ', (string)$m);
    return trim((string)$m);
}

function belirtiKurallari(): array
{
    $dahiliye = 'İç Hastalıkları (Dahiliye)';
    // [poliklinik, agirlik, regex (sadelestirilmis metin uzerinde), gerekce, yedek poliklinik]
    return [
        ['Nöroloji', 3, '/\b(bas|kafa)(im|in|i|m)? ?(agri|zonkla|don)|migren|uyusma|karincalan|\bfelc|nobet|epilepsi|unutkan|denge (sorun|bozuk|kayb)|bayil|\btitreme/', 'Baş ağrısı veya sinir sistemi belirtisi', $dahiliye],
        ['Göz Hastalıkları', 3, '/\bgoz|gorme|gorus|goremiyor|cift gor|bulanik gor|miyop|katarakt|gozluk|sulan/', 'Göz veya görme şikâyeti', null],
        ['Göz Hastalıkları', 1, '/bulanik/', 'Bulanık görme', null],
        ['Kardiyoloji', 3, '/gogus.{0,20}(agri|sikis|baski|yanma)|carpinti|\bkalp|tansiyon|ritim/', 'Göğüs ağrısı, çarpıntı veya kalp belirtisi', $dahiliye],
        ['Göğüs Hastalıkları', 3, '/oksur|nefes darl|nefes al.{0,15}zor|balgam|hirilt|astim|nefes.{0,10}sikis/', 'Öksürük veya nefes şikâyeti', $dahiliye],
        ['Göğüs Hastalıkları', 1, '/gogus.{0,20}(agri|sikis)/', 'Göğüs bölgesi şikâyeti', $dahiliye],
        ['Kulak Burun Boğaz (KBB)', 3, '/kulak|\bbogaz|bademcik|\bburun|sinuz|geniz|yutkun|ses kis|horlama|\bisitme|vizilti|cinlama/', 'Kulak, burun veya boğaz şikâyeti', null],
        ['Dermatoloji (Cildiye)', 3, '/\bcilt|kasin|dokuntu|sivilce|akne|\bleke|sac dok|mantar|egzama|sedef|kurdesen|\bben\b|kizariklik|sucicegi|siyil/', 'Cilt şikâyeti', null],
        ['Gastroenteroloji', 3, '/\bmide|karnim|karnin|\bkarin agri|\bkarin bol|bulanti|kusma|ishal|kabiz|reflu|hazimsiz|safra|karaciger|kanli disk|gaz sanc|gazim|siskinlik/', 'Mide veya sindirim şikâyeti', $dahiliye],
        ['Genel Cerrahi', 3, '/fitik|apandis|safra kesesi|yumru|\bkitle|basur|hemoroid|makat/', 'Yumru, fıtık veya makat şikâyeti', null],
        ['Ortopedi ve Travmatoloji', 3, '/\bkemik|\beklem|\bdiz|belim|\bbel agri|bel fitigi|boyun agri|boynum|\bomuz|kirik|burkul|ayak bilegi|kalca|tendon|\bsirt|\bbilek|topuk|menisk|cikik/', 'Kemik, eklem veya kas-iskelet şikâyeti', null],
        ['Fiziksel Tıp ve Rehabilitasyon', 2, '/belim|\bbel agri|boyun agri|boynum|sirt agri|sirtim|siyatik|tutul|\bomuz|kas agri|eklem agri/', 'Bel, boyun veya kas ağrısı', 'Ortopedi ve Travmatoloji'],
        ['Üroloji', 3, '/idrar|bobrek tas|prostat|mesane|testis|iktidars|sik sik tuvalet|tuvalet.{0,15}(yan|agri)/', 'İdrar yolu şikâyeti', $dahiliye],
        ['Kadın Hastalıkları ve Doğum', 3, '/\bregl|\badet|hamile|gebe|\bdogum|vajin|\bmeme|menopoz|yumurtalik|kasik agri|dogurganlik|\bakinti/', 'Kadın sağlığı şikâyeti', null],
        ['Çocuk Sağlığı ve Hastalıkları', 3, '/\bcocuk|\bbebek|oglum|kizim|yenidogan|\basi\b/', 'Çocuk hasta için', null],
        ['Psikiyatri', 3, '/\bstres|anksiyete|kaygi|panik|depresyon|mutsuz|uykusuz|uyuyamiyor|uyku sorun|endise|takinti|huzursuz|\bmoral|intihar|kendime zarar|yasamak istemiyorum/', 'Ruhsal veya uyku şikâyeti', $dahiliye],
        ['Endokrinoloji ve Metabolizma', 3, '/\bseker|diyabet|tiroid|hormon|guatr|insulin/', 'Şeker, tiroid veya hormon şikâyeti', $dahiliye],
        ['Diş Hekimliği', 4, '/\bdis(im|in|i|ler|lerim)?\b|dis eti|\bcene|implant|kanal tedavi/', 'Diş veya çene şikâyeti', null],
        ['Alerji ve İmmünoloji', 3, '/alerji|alerjik|aksirik|polen|besin alerj|ilac alerj/', 'Alerji belirtisi', $dahiliye],
        ['Romatoloji', 2, '/romatiz|sabah tutuk|eklem.{0,15}(sis|kizar)|lupus/', 'Romatizmal eklem şikâyeti', $dahiliye],
        ['Nefroloji', 2, '/bobrek|kreatinin|ayak.{0,10}sis/', 'Böbrek veya şişlik şikâyeti', $dahiliye],
        ['Beyin ve Sinir Cerrahisi', 1, '/bel fitigi|boyun fitigi|omurga|\bbeyin|sinir sikis/', 'Omurga veya sinir şikâyeti', 'Nöroloji'],
        ['Beslenme ve Diyet', 3, '/diyet|obezite|beslenme|kilo problem|zayiflamak|kilo vermek/', 'Beslenme ve kilo yönetimi', $dahiliye],
        [$dahiliye, 2, '/halsiz|yorgun|bitkin|\bates|kilo ver|kilo al|terleme|susuz|\bsisme|kansiz|anemi/', 'Genel durum belirtisi (halsizlik, ateş vb.)', null],
    ];
}

function belirtiOnerileri(string $metin): array
{
    $m = belirtiNormallestir($metin);
    $sonuc = ['acil' => null, 'oneriler' => [], 'varsayilan' => false];
    if ($m === '') return $sonuc;

    if (preg_match('/intihar|kendime zarar|yasamak istemiyorum|olmek istiyorum/', $m)) {
        $sonuc['acil'] = 'Kendine zarar verme düşüncelerin varsa lütfen hemen 112\'yi ara veya en yakın acil servise git. Yalnız olmak zorunda değilsin.';
    } elseif (preg_match('/gogus.{0,25}(agri|sikis|baski).{0,40}(kol|cene|terleme|nefes)|(kol|cene).{0,25}agri.{0,40}gogus|nefes alam|nefessiz kal|bilincim|bilinc kayb|bayildi|konusam|yuz.{0,12}(kay|egril)|ani.{0,12}(felc|gucsuz)|siddetli kanama|durmayan kanama|zehirlen|havale|kasilma/', $m)) {
        $sonuc['acil'] = 'Bu belirtiler acil olabilir. Beklemeden 112\'yi ara veya en yakın acil servise git.';
    }

    $skor = [];
    foreach (belirtiKurallari() as [$brans, $agirlik, $desen, $gerekce, $yedek]) {
        if (preg_match($desen, $m)) {
            if (!isset($skor[$brans])) $skor[$brans] = ['brans' => $brans, 'puan' => 0, 'gerekce' => [], 'yedek' => $yedek];
            $skor[$brans]['puan'] += $agirlik;
            if (!in_array($gerekce, $skor[$brans]['gerekce'], true)) $skor[$brans]['gerekce'][] = $gerekce;
        }
    }

    if (!$skor) {
        $sonuc['varsayilan'] = true;
        $sonuc['oneriler'][] = [
            'brans' => 'İç Hastalıkları (Dahiliye)', 'puan' => 0,
            'gerekce' => ['Şikâyetin net eşleşmedi; genel değerlendirme için uygundur'], 'yedek' => null
        ];
        return $sonuc;
    }

    usort($skor, fn($a, $b) => $b['puan'] <=> $a['puan']);
    $sonuc['oneriler'] = array_slice(array_values($skor), 0, 3);
    return $sonuc;
}

/* Hastane turu ve ilce kademesine gore: poliklinik => doktor sayisi */
function hastaneProfili(string $tip, string $kademe): array
{
    $g = poliklinikGruplari();
    $populer = [
        'İç Hastalıkları (Dahiliye)', 'Çocuk Sağlığı ve Hastalıkları', 'Kadın Hastalıkları ve Doğum',
        'Genel Cerrahi', 'Ortopedi ve Travmatoloji', 'Kardiyoloji', 'Göz Hastalıkları'
    ];
    $liste = [];
    $cokDoktorlu = false; // populer branslarda 2 doktor

    switch ($tip) {
        case 'ADSM':
            $profil = [];
            foreach ($g['dis'] as $b) $profil[$b] = ($b === 'Diş Hekimliği') ? 3 : 1;
            return $profil;
        case 'DH':
            $liste = ['D' => $g['cekirdek'], 'C' => $g['orta'], 'B' => $g['genis'], 'A' => $g['tam']][$kademe] ?? $g['orta'];
            $cokDoktorlu = in_array($kademe, ['B', 'A'], true);
            break;
        case 'OH':
            $liste = ['D' => $g['orta'], 'C' => $g['orta'], 'B' => $g['genis'], 'A' => $g['tam']][$kademe] ?? $g['orta'];
            break;
        case 'EAH':
        case 'SH':
        case 'UH':
            $liste = $g['tam'];
            $cokDoktorlu = true;
            break;
        case 'KDC':
            $liste = [
                'Kadın Hastalıkları ve Doğum', 'Çocuk Sağlığı ve Hastalıkları', 'Çocuk Cerrahisi',
                'Çocuk Kardiyolojisi', 'Çocuk Nörolojisi', 'Çocuk Endokrinolojisi', 'Beslenme ve Diyet'
            ];
            $cokDoktorlu = true;
            break;
        case 'RSH':
            $liste = ['Psikiyatri', 'Çocuk ve Ergen Ruh Sağlığı', 'Nöroloji', 'Beslenme ve Diyet'];
            $cokDoktorlu = true;
            break;
        case 'GGH':
            $liste = [
                'Göğüs Hastalıkları', 'Göğüs Cerrahisi', 'Enfeksiyon Hastalıkları',
                'Alerji ve İmmünoloji', 'Kardiyoloji', 'Radyasyon Onkolojisi'
            ];
            $cokDoktorlu = true;
            break;
        default:
            $liste = $g['cekirdek'];
    }

    $profil = [];
    foreach ($liste as $b) {
        $ana = in_array($b, $populer, true) || in_array($b, ['Göğüs Hastalıkları', 'Psikiyatri'], true);
        $profil[$b] = ($cokDoktorlu && $ana) ? 2 : 1;
    }
    return $profil;
}

function kademeBul(?int $nufus): string
{
    if ($nufus === null) return 'C';
    if ($nufus >= 300000) return 'A';
    if ($nufus >= 100000) return 'B';
    if ($nufus >= 30000)  return 'C';
    return 'D';
}

/* Bir ilin ilcelerine gore hastane listesi uret: [ilce_id, ad, tip, kademe] */
function hastaneleriUret(string $ilAd, array $ilceler): array
{
    $toplam = 0;
    $nufusVar = false;
    foreach ($ilceler as $x) {
        if ($x['nufus'] !== null) { $nufusVar = true; $toplam += (int)$x['nufus']; }
    }
    $merkez = 0;
    if ($nufusVar) {
        $maks = -1;
        foreach ($ilceler as $k => $x) {
            $p = $x['nufus'] ?? -1;
            if ($p > $maks) { $maks = $p; $merkez = $k; }
        }
    } else {
        foreach ($ilceler as $k => $x) {
            if ($x['ad'] === 'Merkez' || $x['ad'] === $ilAd) { $merkez = $k; break; }
        }
    }

    $sonuc = [];
    foreach ($ilceler as $k => $x) {
        $on = ($x['ad'] === 'Merkez') ? $ilAd : $x['ad'];
        $kd = kademeBul($x['nufus'] === null ? null : (int)$x['nufus']);
        $nufus = $x['nufus'] === null ? 0 : (int)$x['nufus'];
        $var = [];
        $ekle = function (string $tip, string $ad, string $kademe) use (&$sonuc, &$var, $x) {
            if (isset($var[$ad])) return;
            $var[$ad] = true;
            $sonuc[] = [(int)$x['id'], $ad, $tip, $kademe];
        };

        $ekle('DH', $on . ' Devlet Hastanesi', $kd);
        $ekle('ADSM', $on . ' Ağız ve Diş Sağlığı Merkezi', $kd);
        if ($nufus >= 60000)  $ekle('OH', 'Özel ' . $on . ' Hastanesi', $kd);
        if ($nufus >= 200000) $ekle('EAH', $on . ' Eğitim ve Araştırma Hastanesi', 'A');
        if ($kd === 'A') {
            $ekle('OH', 'Özel ' . $on . ' Şifa Hastanesi', $kd);
            $ekle('KDC', $on . ' Kadın Doğum ve Çocuk Hastalıkları Hastanesi', 'A');
        }

        if ($k === $merkez) {
            $ekle('EAH', $on . ' Eğitim ve Araştırma Hastanesi', 'A');
            $ekle('UH', $ilAd . ' Üniversitesi Uygulama ve Araştırma Hastanesi', 'A');
            if ($toplam >= 700000) {
                $ekle('SH', $ilAd . ' Şehir Hastanesi', 'A');
                $ekle('KDC', $on . ' Kadın Doğum ve Çocuk Hastalıkları Hastanesi', 'A');
            }
            if ($toplam >= 1500000) {
                $ekle('RSH', $ilAd . ' Ruh Sağlığı ve Hastalıkları Hastanesi', 'A');
                $ekle('GGH', $ilAd . ' Göğüs Hastalıkları Hastanesi', 'A');
            }
        }
    }
    return $sonuc;
}

function ebobBul(int $a, int $b): int
{
    while ($b !== 0) { [$a, $b] = [$b, $a % $b]; }
    return $a;
}

/* n numarali doktor icin benzersiz ad-soyad (n farkli oldukca isim de farklidir) */
function doktorAdiUret(int $n): array
{
    static $erkek = null, $kadin, $soyad, $ikE, $ikK, $F, $S, $M, $P;
    if ($erkek === null) {
        $erkek = array_values(array_unique([
            'Ahmet','Mehmet','Mustafa','Hasan','Hüseyin','İbrahim','Ali','Murat','Yusuf','Emre',
            'Burak','Can','Cem','Deniz','Efe','Eren','Fatih','Gökhan','Hakan','Kaan',
            'Kerem','Oğuz','Onur','Ozan','Serkan','Tolga','Uğur','Volkan','Yasin','Barış',
            'Alper','Berkay','Doğan','Ersin','Halil','İlker','Koray','Mert','Necati','Orhan',
            'Adnan','Atakan','Batuhan','Bülent','Cihan','Çağrı','Erdem','Erkan','Ferhat','Furkan',
            'Gürkan','Harun','İsmail','Kadir','Kemal','Levent','Metin','Naci','Necmi','Okan',
            'Ömer','Rıza','Sedat','Selim','Sinan','Soner','Taner','Taha','Tarık','Tuncay',
            'Turgut','Ufuk','Veli','Yılmaz','Yiğit','Zafer','Zeki','Abdullah','Ayhan','Bekir',
            'Cengiz','Coşkun','Doruk','Emin','Engin','Eyüp','Hamza','Kürşat','Mazlum','Nihat',
            'Osman','Polat','Recep','Semih','Süleyman','Tayfun','Umut','Vedat','Yunus','Alperen'
        ]));
        $kadin = array_values(array_unique([
            'Ayşe','Fatma','Zeynep','Elif','Merve','Esra','Büşra','Seda','Derya','Pınar',
            'Selin','Sevgi','Tuba','Yasemin','Burcu','Cansu','Damla','Ebru','Funda','Gamze',
            'Hande','İrem','Jale','Kader','Leyla','Melek','Nazlı','Özge','Rabia','Sibel',
            'Tülin','Ülkü','Vildan','Yeliz','Zehra','Aslı','Bahar','Ceren','Dilara','Ece',
            'Aylin','Banu','Belgin','Betül','Beyza','Duygu','Emel','Feride','Gül','Gülsüm',
            'Hacer','Hatice','Havva','Hülya','Işıl','Kübra','Lale','Meltem','Meryem','Nalan',
            'Nehir','Neslihan','Nilay','Nilüfer','Nurcan','Özlem','Perihan','Rukiye','Sema','Serap',
            'Sevil','Songül','Sultan','Şeyma','Tuğba','Ümran','Yağmur','Yıldız','Zübeyde','Aysel',
            'Arzu','Ayten','Bilge','Çiğdem','Dilek','Eda','Gizem','Gönül','Handan','İnci',
            'Mine','Nesrin','Sevda','Sinem','Berna','Cemre','Dicle','Gökçe','Hilal','Melike'
        ]));
        $soyad = array_values(array_unique([
            'Yılmaz','Kaya','Demir','Şahin','Çelik','Yıldız','Yıldırım','Öztürk','Aydın','Özdemir',
            'Arslan','Doğan','Kılıç','Aslan','Çetin','Kara','Koç','Kurt','Özkan','Şimşek',
            'Polat','Korkmaz','Çakır','Erdoğan','Acar','Güler','Tekin','Bulut','Aksoy','Karaca',
            'Ünal','Türkmen','Taş','Bozkurt','Gündüz','Güneş','Bayram','Uçar','Duman','Sönmez',
            'Avcı','Aktaş','Karahan','Ateş','Tunç','Işık','Erdem','Özer','Kaplan','Yavuz',
            'Başaran','Coşkun','Ekinci','Eroğlu','Gökçe','Hızlı','Kalkan','Mutlu','Önal','Sezer',
            'Toprak','Vural','Yaman','Zengin','Albayrak','Bilgin','Cengiz','Dinç','Ersoy','Fidan',
            'Gül','Harman','İnan','Karakaya','Levent','Nalbant','Oral','Peker','Sarı','Tuna',
            'Akın','Aksu','Alkan','Altın','Arıkan','Atasoy','Aydoğan','Bakır','Balcı','Barut',
            'Bayrak','Bektaş','Bozdağ','Büyük','Çalışkan','Çavuş','Çiftçi','Çolak','Dağ','Demirci',
            'Dikmen','Durmuş','Ergin','Ertürk','Eser','Gençer','Gökmen','Gür','Güven','Hacıoğlu',
            'Han','Kahraman','Kandemir','Karabulut','Karadağ','Karakuş','Kartal','Keskin','Kocabaş','Konak',
            'Köse','Kutlu','Mercan','Meral','Nar','Ocak','Oktay','Ongun','Öz','Özbek',
            'Özcan','Özgür','Pehlivan','Sağlam','Sarıkaya','Savaş','Sevim','Soylu','Şen','Şener',
            'Tan','Tanrıverdi','Tatlı','Tezcan','Topal','Tosun','Turan','Tüfekçi','Uysal','Uzun',
            'Ülker','Yalçın','Yazıcı','Yetkin','Yurt','Yüksel','Zorlu','Akgül','Bal','Cömert',
            'Doğru','Ergül','Gökalp','Hancı','Kabak','Lokman','Merdan','Nazlıgül','Okumuş','Pamuk'
        ]));
        $ikE = ['Kuzey', 'Aras', 'Ayaz', 'Alp', 'Egemen', 'Yağız'];
        $ikK = ['Nur', 'Su', 'Naz', 'Ela', 'Defne', 'Melis'];
        $F = count($erkek) + count($kadin);
        $S = count($soyad);
        $M = $F * $S;
        $P = 7919;
        while (ebobBul($P, $M) !== 1) $P += 2;
    }

    $cevrim = intdiv($n, $M);
    $r = $n % $M;
    $pos = ($r * $P) % $M;      // karistirilmis ama birebir eslesme
    $fi = $pos % $F;
    $si = intdiv($pos, $F);
    $erkekMi = $fi < count($erkek);
    $ad = $erkekMi ? $erkek[$fi] : $kadin[$fi - count($erkek)];
    if ($cevrim > 0) {
        $ad .= ' ' . ($erkekMi ? $ikE[($cevrim - 1) % count($ikE)] : $ikK[($cevrim - 1) % count($ikK)]);
    }
    return [$ad . ' ' . $soyad[$si], $erkekMi];
}

/* Bir hastanenin doktor satirlari (deterministik). $ofset hastanenin ilk doktor numarasi. */
function doktorSatirlari(string $tip, string $kademe, int $ofset): array
{
    $cerrahi = [
        'Beyin ve Sinir Cerrahisi', 'Ortopedi ve Travmatoloji', 'Genel Cerrahi', 'Üroloji',
        'Kulak Burun Boğaz (KBB)', 'Göz Hastalıkları', 'Kalp ve Damar Cerrahisi',
        'Göğüs Cerrahisi', 'Çocuk Cerrahisi', 'Plastik ve Estetik Cerrahi'
    ];
    $dis = ['Diş Hekimliği', 'Ağız, Diş ve Çene Cerrahisi', 'Ortodonti', 'Pedodonti (Çocuk Diş)',
            'Endodonti', 'Periodontoloji', 'Protetik Diş Tedavisi'];
    $mesailer = [
        ['08:00:00', '17:00:00'], ['08:00:00', '16:00:00'],
        ['09:00:00', '17:00:00'], ['08:30:00', '16:30:00']
    ];

    $satirlar = [];
    $i = 0;
    foreach (hastaneProfili($tip, $kademe) as $brans => $adet) {
        for ($k = 0; $k < $adet; $k++) {
            $n = $ofset + $i;
            [$ad] = doktorAdiUret($n);

            if ($tip === 'ADSM' || in_array($brans, $dis, true)) $unvan = 'Dt.';
            elseif ($tip === 'UH' && $n % 5 === 0)               $unvan = 'Prof. Dr.';
            elseif (in_array($tip, ['UH', 'EAH', 'SH'], true) && $n % 4 === 0) $unvan = 'Doç. Dr.';
            elseif (in_array($brans, $cerrahi, true))            $unvan = 'Op. Dr.';
            else                                                 $unvan = 'Uzm. Dr.';

            $mesai = $mesailer[$n % 4];
            $satirlar[] = [
                'ad' => $ad, 'sira' => $i, 'brans' => $brans, 'unvan' => $unvan,
                'bas' => $mesai[0], 'bit' => $mesai[1]
            ];
            $i++;
        }
    }
    return $satirlar;
}
// ==== VERI URETIMI BITIS ====

/* =========================================
   3. VERITABANI
========================================= */

try {
    $pdo = new PDO(
        "mysql:host=$host;charset=utf8mb4",
        $dbuser,
        $dbpass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec("
        CREATE DATABASE IF NOT EXISTS randevu_sistemi
        CHARACTER SET utf8mb4
        COLLATE utf8mb4_turkish_ci
    ");
    $pdo->exec("USE randevu_sistemi");
    $pdo->exec("SET time_zone = '+03:00'");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ad_soyad VARCHAR(100) NOT NULL,
            email VARCHAR(150) NOT NULL UNIQUE,
            parola VARCHAR(255) NOT NULL,
            rol ENUM('hasta','doktor') NOT NULL DEFAULT 'hasta',
            uzmanlik VARCHAR(100) DEFAULT NULL,
            il VARCHAR(80) DEFAULT NULL,
            ilce VARCHAR(80) DEFAULT NULL,
            hastane VARCHAR(150) DEFAULT NULL,
            poliklinik VARCHAR(120) DEFAULT NULL,
            mesai_baslangic TIME DEFAULT NULL,
            mesai_bitis TIME DEFAULT NULL,
            olusturma_tarihi TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");

    $yeniKolonlar = [
        'il' => "VARCHAR(80) DEFAULT NULL",
        'ilce' => "VARCHAR(80) DEFAULT NULL",
        'hastane' => "VARCHAR(150) DEFAULT NULL",
        'poliklinik' => "VARCHAR(120) DEFAULT NULL",
        'mesai_baslangic' => "TIME DEFAULT NULL",
        'mesai_bitis' => "TIME DEFAULT NULL",
        'unvan' => "VARCHAR(30) DEFAULT NULL",
        'aktif' => "TINYINT(1) NOT NULL DEFAULT 1",
        'hastane_id' => "INT DEFAULT NULL"
    ];
    foreach ($yeniKolonlar as $kolon => $tip) {
        $kontrol = $pdo->query("SHOW COLUMNS FROM users LIKE " . $pdo->quote($kolon))->fetch();
        if (!$kontrol) {
            $pdo->exec("ALTER TABLE users ADD COLUMN `$kolon` $tip");
        }
    }
    $indeksVar = $pdo->query("SHOW INDEX FROM users WHERE Key_name = 'idx_hastane_pol'")->fetch();
    if (!$indeksVar) {
        $pdo->exec("ALTER TABLE users ADD INDEX idx_hastane_pol (hastane_id, poliklinik)");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS appointments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            hasta_id INT NOT NULL,
            doktor_id INT NOT NULL,
            randevu_tarihi DATETIME NOT NULL,
            aciklama VARCHAR(500) DEFAULT NULL,
            durum ENUM('Bekliyor','Onaylandi','Tamamlandi','Iptal') NOT NULL DEFAULT 'Bekliyor',
            olusturma_tarihi TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (hasta_id) REFERENCES users(id),
            FOREIGN KEY (doktor_id) REFERENCES users(id),
            INDEX idx_durum_tarih (durum, olusturma_tarihi),
            INDEX idx_doktor_tarih (doktor_id, randevu_tarihi)
        ) ENGINE=InnoDB
    ");

    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        appointment_id INT DEFAULT NULL,
        mesaj VARCHAR(255) NOT NULL,
        okundu TINYINT(1) NOT NULL DEFAULT 0,
        olusturma_tarihi TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_okundu (user_id, okundu),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ayarlar (
        anahtar VARCHAR(50) PRIMARY KEY,
        deger VARCHAR(100) NOT NULL
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS iller (
        id INT PRIMARY KEY,
        ad VARCHAR(80) NOT NULL
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ilceler (
        id INT PRIMARY KEY,
        il_id INT NOT NULL,
        ad VARCHAR(80) NOT NULL,
        nufus INT DEFAULT NULL,
        INDEX idx_il (il_id)
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hastaneler (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ilce_id INT NOT NULL,
        ad VARCHAR(150) NOT NULL,
        tip VARCHAR(10) NOT NULL,
        kademe CHAR(1) NOT NULL DEFAULT 'C',
        doktor_ofset INT NOT NULL DEFAULT 0,
        doktor_sayisi INT NOT NULL DEFAULT 0,
        doktor_olusturuldu TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_ilce_ad (ilce_id, ad),
        INDEX idx_ilce (ilce_id)
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS favoriler (
        user_id INT NOT NULL,
        doktor_id INT NOT NULL,
        olusturma_tarihi TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, doktor_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (doktor_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS son_aramalar (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        hastane_id INT NOT NULL,
        poliklinik VARCHAR(120) NOT NULL,
        son_tarih TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_arama (user_id, hastane_id, poliklinik),
        INDEX idx_user_tarih (user_id, son_tarih),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS parola_sifirlama (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        son_kullanma DATETIME NOT NULL,
        olusturma_tarihi TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_token (token_hash),
        INDEX idx_user (user_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $veriSurumu = $pdo->query("SELECT deger FROM ayarlar WHERE anahtar='veri_surum'")->fetchColumn();
    $kurulumGerekli = ($veriSurumu !== VERI_SURUM);

    if (!$kurulumGerekli) {
        // 1 dakikayı geçen bekleyen randevuları onayla.
        $onayBekleyen = $pdo->query("SELECT id, hasta_id FROM appointments WHERE durum='Bekliyor' AND olusturma_tarihi <= DATE_SUB(NOW(), INTERVAL 1 MINUTE)")->fetchAll(PDO::FETCH_ASSOC);
        $pdo->exec("UPDATE appointments SET durum='Onaylandi' WHERE durum='Bekliyor' AND olusturma_tarihi <= DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
        if ($onayBekleyen) {
            $bildirimEkle = $pdo->prepare("INSERT INTO notifications (user_id, appointment_id, mesaj) VALUES (?, ?, ?)");
            foreach ($onayBekleyen as $b) {
                $bildirimEkle->execute([(int)$b['hasta_id'], (int)$b['id'], 'Randevunuz otomatik olarak onaylandı.']);
            }
        }
        // Tarihi geçmiş onaylı randevular tamamlandı sayılır.
        $pdo->exec("UPDATE appointments SET durum='Tamamlandi' WHERE durum='Onaylandi' AND randevu_tarihi < NOW()");
    }

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    exit(
        '<div style="font-family:Arial;padding:30px">' .
        '<h2>Veritabanı bağlantı hatası</h2>' .
        '<p>XAMPP üzerinde MySQL servisinin açık olduğunu kontrol et.</p>' .
        '<p>MySQL kullanıcı adı ve parolasını PHP dosyasının başındaki ' .
        '$dbuser ve $dbpass alanlarından kontrol et.</p>' .
        '<p>Veritabanı oluşturma yetkin de olmalı.</p>' .
        '<p style="color:#888;font-size:13px">Ayrıntı: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>' .
        '</div>'
    );
}

/* =========================================
   4. YARDIMCI FONKSIYONLAR
========================================= */

function h($deger) {
    return htmlspecialchars((string)$deger, ENT_QUOTES, 'UTF-8');
}

function mesajGonder($mesaj, $sayfa = 'index.php') {
    $_SESSION['mesaj'] = $mesaj;
    header('Location: ' . $sayfa);
    exit;
}

function jsonYanit($veri, int $kod = 200) {
    http_response_code($kod);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($veri, JSON_UNESCAPED_UNICODE);
    exit;
}

function durumAdi($durum) {
    $liste = [
        'Bekliyor' => 'Bekliyor',
        'Onaylandi' => 'Onaylandı',
        'Tamamlandi' => 'Tamamlandı',
        'Iptal' => 'İptal edildi'
    ];
    return $liste[$durum] ?? $durum;
}

function tarihAdi($tarih) {
    return date('d.m.Y H:i', strtotime($tarih));
}

function gunAy($tarih) {
    $aylar = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
    $ts = strtotime($tarih);
    return [date('d', $ts), $aylar[(int)date('n', $ts) - 1]];
}

function gunAdi($tarih) {
    $gunler = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];
    return $gunler[(int)date('w', strtotime($tarih))];
}

/* Ilk kurulum: tum illeri/ilceleri kaydet, hastaneleri uret. */
function kurulumUygula(PDO $pdo, array $gelenIller): array
{
    $tumIller = iller81();
    $pdo->beginTransaction();
    try {
        $pdo->exec("DELETE FROM hastaneler");
        $pdo->exec("DELETE FROM ilceler");
        $pdo->exec("DELETE FROM iller");
        // Onceki surumlerdeki doktorlar pasif yapilir (eski randevular bozulmasin).
        $pdo->exec("UPDATE users SET aktif = 0 WHERE rol = 'doktor'");

        $ilEkle = $pdo->prepare("INSERT INTO iller (id, ad) VALUES (?, ?)");
        $ilceEkle = $pdo->prepare("INSERT INTO ilceler (id, il_id, ad, nufus) VALUES (?, ?, ?, ?)");
        $hastaneEkle = $pdo->prepare("
            INSERT INTO hastaneler (ilce_id, ad, tip, kademe, doktor_ofset, doktor_sayisi)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        foreach ($tumIller as $i => $ad) {
            $ilEkle->execute([$i + 1, $ad]);
        }

        $ofset = 0;
        $hastaneSayisi = 0;
        foreach ($gelenIller as $il) {
            $ilId = (int)$il['id'];
            $ilAd = $tumIller[$ilId - 1];
            $ilceler = [];
            foreach ($il['ilceler'] as $x) {
                $ilceEkle->execute([(int)$x['id'], $ilId, $x['ad'], $x['nufus']]);
                $ilceler[] = ['id' => (int)$x['id'], 'ad' => $x['ad'], 'nufus' => $x['nufus']];
            }
            foreach (hastaneleriUret($ilAd, $ilceler) as $h) {
                $sayi = array_sum(hastaneProfili($h[2], $h[3]));
                $hastaneEkle->execute([$h[0], $h[1], $h[2], $h[3], $ofset, $sayi]);
                $ofset += $sayi;
                $hastaneSayisi++;
            }
        }

        $pdo->prepare("REPLACE INTO ayarlar (anahtar, deger) VALUES ('veri_surum', ?)")->execute([VERI_SURUM]);
        $pdo->commit();
        return ['hastane' => $hastaneSayisi, 'doktor' => $ofset];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/* Bir hastanenin doktorlarini ilk istekte olustur (tembel uretim). */
function hastaneDoktorlariniOlustur(PDO $pdo, array $h): void
{
    if ((int)$h['doktor_olusturuldu'] === 1) return;

    $ortakHash = password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT); // doktor girisi kapali
    $ekle = $pdo->prepare("
        INSERT INTO users
            (ad_soyad, email, parola, rol, unvan, uzmanlik, il, ilce, hastane, hastane_id,
             poliklinik, mesai_baslangic, mesai_bitis, aktif)
        VALUES (?, ?, ?, 'doktor', ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE aktif = 1
    ");

    $pdo->beginTransaction();
    try {
        foreach (doktorSatirlari($h['tip'], $h['kademe'], (int)$h['doktor_ofset']) as $d) {
            $ekle->execute([
                $d['ad'], 'dr.h' . (int)$h['id'] . '.' . $d['sira'] . '@randevu.local', $ortakHash,
                $d['unvan'], $d['brans'], $h['il_ad'], $h['ilce_ad'], $h['ad'], (int)$h['id'],
                $d['brans'], $d['bas'], $d['bit']
            ]);
        }
        $pdo->prepare("UPDATE hastaneler SET doktor_olusturuldu = 1 WHERE id = ?")->execute([(int)$h['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$girisli = isset($_SESSION['kullanici_id']);
$id = (int)($_SESSION['kullanici_id'] ?? 0);
$rol = $_SESSION['rol'] ?? '';
$ad = $_SESSION['ad'] ?? '';

/* =========================================
   5. KURULUM API'SI (tarayici, il/ilce listesini gonderir)
========================================= */

if (($_GET['api'] ?? '') === 'kurulum' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$kurulumGerekli) {
        jsonYanit(['ok' => false, 'hata' => 'Kurulum zaten tamamlanmış.'], 409);
    }
    $gelen = json_decode((string)file_get_contents('php://input'), true);
    if (
        !is_array($gelen) || !isset($gelen['csrf']) || !is_string($gelen['csrf']) ||
        !hash_equals($_SESSION['csrf'], $gelen['csrf']) || !isset($gelen['iller']) || !is_array($gelen['iller'])
    ) {
        jsonYanit(['ok' => false, 'hata' => 'Geçersiz istek.'], 400);
    }

    // Doğrulama: 81 il, her ilde en az 1 ilçe, temiz veri.
    $temiz = [];
    $gorulen = [];
    foreach ($gelen['iller'] as $il) {
        $ilId = (int)($il['id'] ?? 0);
        if ($ilId < 1 || $ilId > 81 || isset($gorulen[$ilId]) || empty($il['ilceler']) || !is_array($il['ilceler'])) {
            jsonYanit(['ok' => false, 'hata' => 'İl verisi hatalı.'], 400);
        }
        $gorulen[$ilId] = true;
        $ilceler = [];
        $ilceIdleri = [];
        foreach ($il['ilceler'] as $x) {
            $xid = (int)($x['id'] ?? 0);
            $xad = trim((string)($x['ad'] ?? ''));
            if ($xid < 1 || $xad === '' || mb_strlen($xad) > 80 || isset($ilceIdleri[$xid])) {
                jsonYanit(['ok' => false, 'hata' => 'İlçe verisi hatalı.'], 400);
            }
            $ilceIdleri[$xid] = true;
            $nufus = (isset($x['nufus']) && is_numeric($x['nufus']) && $x['nufus'] >= 0) ? (int)$x['nufus'] : null;
            $ilceler[] = ['id' => $xid, 'ad' => $xad, 'nufus' => $nufus];
        }
        $temiz[] = ['id' => $ilId, 'ilceler' => $ilceler];
    }
    if (count($temiz) !== 81) {
        jsonYanit(['ok' => false, 'hata' => '81 ilin tamamı gelmedi.'], 400);
    }
    usort($temiz, fn($a, $b) => $a['id'] <=> $b['id']);

    set_time_limit(0);
    try {
        $sonuc = kurulumUygula($pdo, $temiz);
        jsonYanit(['ok' => true] + $sonuc);
    } catch (Throwable $e) {
        jsonYanit(['ok' => false, 'hata' => 'Kayıt sırasında hata: ' . $e->getMessage()], 500);
    }
}

/* Veri hazir degilse kurulum ekranini goster. */
if ($kurulumGerekli) {
    $illerJs = json_encode(iller81(), JSON_UNESCAPED_UNICODE);
    ?>
<!DOCTYPE html>
<html lang="tr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>RandevuPlus · İlk Kurulum</title>
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:'Segoe UI',system-ui,Arial,sans-serif;color:#2a2145;background:linear-gradient(120deg,#faf5ff,#ede9fe,#fce7f3,#e0f2fe,#ccfbf1,#faf5ff);background-size:300% 300%;animation:renkGecisi 26s ease-in-out infinite}
@keyframes renkGecisi{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}
@media (prefers-reduced-motion:reduce){body{animation:none}}
.k{width:min(560px,92%);background:rgba(255,255,255,.85);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);border-radius:26px;padding:34px;box-shadow:0 24px 60px rgba(91,33,182,.15);text-align:center}
.k h1{margin:0 0 8px;font-size:26px}.k p{color:#6f6a85;line-height:1.7}
.bar{height:14px;border-radius:20px;background:#efe9fb;overflow:hidden;margin:22px 0 10px}
.bar i{display:block;height:100%;width:0;background:linear-gradient(135deg,#8b5cf6,#ec4899);transition:width .25s}
#durum{font-weight:700;color:#5b21b6;min-height:24px}
button{border:0;cursor:pointer;border-radius:13px;padding:12px 20px;font:inherit;font-weight:700;background:linear-gradient(135deg,#8b5cf6,#ec4899);color:#fff;margin-top:14px}
.hata{color:#be123c}
</style></head><body>
<div class="k">
  <div style="font-size:42px">✚</div>
  <h1>Türkiye verileri yükleniyor</h1>
  <p>81 ilin tüm ilçeleri alınıyor ve hastaneler ile poliklinikler hazırlanıyor. Bu işlem sadece bir kez yapılır ve internet gerektirir.</p>
  <div class="bar"><i id="ilerleme"></i></div>
  <div id="durum">Başlıyor...</div>
  <button id="tekrar" hidden type="button">Tekrar dene</button>
</div>
<script>
const ILLER = <?= $illerJs ?>;
const CSRF = <?= json_encode($_SESSION['csrf']) ?>;
const durum = document.getElementById('durum'), bar = document.getElementById('ilerleme'), tekrar = document.getElementById('tekrar');

async function ilceleriGetir(kod) {
    for (const alanlar of ['id,name,population', 'id,name']) {
        for (let deneme = 0; deneme < 2; deneme++) {
            try {
                const r = await fetch('https://api.turkiyeapi.dev/v2/provinces/' + kod + '/districts?limit=100&fields=' + alanlar, { headers: { 'Accept': 'application/json' } });
                if (!r.ok) continue;
                const j = await r.json();
                const liste = (j.data || []).map(x => ({
                    id: x.id, ad: x.name,
                    nufus: (typeof x.population === 'number') ? x.population : null
                })).filter(x => x.id && x.ad);
                if (liste.length) return liste;
            } catch (e) { /* tekrar dene */ }
        }
    }
    throw new Error(ILLER[kod - 1] + ' için ilçeler alınamadı');
}

async function kur() {
    tekrar.hidden = true; durum.className = ''; bar.style.width = '0';
    try {
        const sonuc = new Array(81);
        let sira = 0, bitti = 0;
        async function isci() {
            while (sira < 81) {
                const kod = ++sira;
                sonuc[kod - 1] = { id: kod, ilceler: await ilceleriGetir(kod) };
                bitti++;
                bar.style.width = Math.round(bitti / 81 * 85) + '%';
                durum.textContent = bitti + ' / 81 il alındı';
            }
        }
        await Promise.all([isci(), isci(), isci(), isci(), isci()]);

        durum.textContent = 'Hastaneler ve poliklinikler hazırlanıyor...';
        bar.style.width = '92%';
        const yanit = await fetch('index.php?api=kurulum', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf: CSRF, iller: sonuc })
        });
        const j = await yanit.json();
        if (!j.ok) throw new Error(j.hata || 'Kurulum başarısız');
        bar.style.width = '100%';
        durum.textContent = '✔ Hazır: ' + j.hastane + ' hastane, ' + j.doktor + ' doktor. Yönlendiriliyor...';
        setTimeout(() => location.href = 'index.php', 1200);
    } catch (e) {
        durum.className = 'hata';
        durum.textContent = 'Hata: ' + e.message + ' (İnternet bağlantını kontrol et.)';
        tekrar.hidden = false;
    }
}
tekrar.addEventListener('click', kur);
kur();
</script></body></html>
<?php
    exit;
}

/* =========================================
   6. VERI API'LERI (giris yapmis hasta)
========================================= */

/* Favori ve son arama islemleri (POST, JSON) */
if (isset($_GET['api']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$girisli || $rol !== 'hasta') {
        jsonYanit(['ok' => false, 'hata' => 'Giriş gerekli.'], 401);
    }
    $gelen = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($gelen) || !isset($gelen['csrf']) || !is_string($gelen['csrf']) || !hash_equals($_SESSION['csrf'], $gelen['csrf'])) {
        jsonYanit(['ok' => false, 'hata' => 'Geçersiz istek.'], 400);
    }
    $api = $_GET['api'];

    if ($api === 'favori') {
        $doktorId = (int)($gelen['doktor_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND rol = 'doktor' AND aktif = 1");
        $stmt->execute([$doktorId]);
        if (!$stmt->fetch()) {
            jsonYanit(['ok' => false, 'hata' => 'Doktor bulunamadı.'], 404);
        }
        $sil = $pdo->prepare("DELETE FROM favoriler WHERE user_id = ? AND doktor_id = ?");
        $sil->execute([$id, $doktorId]);
        if ($sil->rowCount()) {
            jsonYanit(['ok' => true, 'favori' => false]);
        }
        $pdo->prepare("INSERT IGNORE INTO favoriler (user_id, doktor_id) VALUES (?, ?)")->execute([$id, $doktorId]);
        jsonYanit(['ok' => true, 'favori' => true]);
    }

    if ($api === 'son_temizle') {
        $pdo->prepare("DELETE FROM son_aramalar WHERE user_id = ?")->execute([$id]);
        jsonYanit(['ok' => true]);
    }

    jsonYanit(['ok' => false, 'hata' => 'Bilinmeyen istek.'], 404);
}

if (isset($_GET['api']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$girisli || $rol !== 'hasta') {
        jsonYanit(['hata' => 'Giriş gerekli.'], 401);
    }
    $api = $_GET['api'];

    if ($api === 'ilceler') {
        $stmt = $pdo->prepare("SELECT id, ad FROM ilceler WHERE il_id = ? ORDER BY ad");
        $stmt->execute([(int)($_GET['il'] ?? 0)]);
        jsonYanit($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($api === 'hastaneler') {
        $stmt = $pdo->prepare("SELECT id, ad, tip FROM hastaneler WHERE ilce_id = ? ORDER BY FIELD(tip,'UH','SH','EAH','DH','KDC','RSH','GGH','OH','ADSM'), ad");
        $stmt->execute([(int)($_GET['ilce'] ?? 0)]);
        $liste = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($liste as &$x) { $x['tur'] = hastaneTipEtiketi($x['tip']); }
        jsonYanit($liste);
    }

    if ($api === 'poliklinikler') {
        $stmt = $pdo->prepare("SELECT tip, kademe FROM hastaneler WHERE id = ?");
        $stmt->execute([(int)($_GET['hastane'] ?? 0)]);
        $h = $stmt->fetch(PDO::FETCH_ASSOC);
        jsonYanit($h ? array_keys(hastaneProfili($h['tip'], $h['kademe'])) : []);
    }

    if ($api === 'doktorlar') {
        $stmt = $pdo->prepare("
            SELECT h.*, i.ad AS ilce_ad, l.ad AS il_ad
            FROM hastaneler h
            JOIN ilceler i ON i.id = h.ilce_id
            JOIN iller l ON l.id = i.il_id
            WHERE h.id = ?
        ");
        $stmt->execute([(int)($_GET['hastane'] ?? 0)]);
        $h = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$h) jsonYanit([]);
        try {
            hastaneDoktorlariniOlustur($pdo, $h);
        } catch (Throwable $e) {
            jsonYanit(['hata' => 'Doktorlar hazırlanamadı.'], 500);
        }
        $pol = trim((string)($_GET['poliklinik'] ?? ''));
        $stmt = $pdo->prepare("
            SELECT u.id, u.ad_soyad AS ad, u.unvan, u.poliklinik,
                   TIME_FORMAT(u.mesai_baslangic, '%H:%i') AS baslangic,
                   TIME_FORMAT(u.mesai_bitis, '%H:%i') AS bitis,
                   (f.doktor_id IS NOT NULL) AS favori
            FROM users u
            LEFT JOIN favoriler f ON f.doktor_id = u.id AND f.user_id = ?
            WHERE u.rol = 'doktor' AND u.aktif = 1 AND u.hastane_id = ? AND u.poliklinik = ?
            ORDER BY favori DESC, u.ad_soyad
        ");
        $stmt->execute([$id, (int)$h['id'], $pol]);
        $liste = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Gecerli bir poliklinik arandiysa "son aramalar" listesine yaz (en fazla 12 kayit tutulur).
        if ($liste && isset(hastaneProfili($h['tip'], $h['kademe'])[$pol])) {
            $pdo->prepare("
                INSERT INTO son_aramalar (user_id, hastane_id, poliklinik) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE son_tarih = NOW()
            ")->execute([$id, (int)$h['id'], $pol]);
            $pdo->prepare("
                DELETE FROM son_aramalar WHERE user_id = ? AND id NOT IN (
                    SELECT id FROM (
                        SELECT id FROM son_aramalar WHERE user_id = ? ORDER BY son_tarih DESC, id DESC LIMIT 12
                    ) AS t
                )
            ")->execute([$id, $id]);
        }
        jsonYanit($liste);
    }

    if ($api === 'dolu') {
        $stmt = $pdo->prepare("
            SELECT DATE_FORMAT(randevu_tarihi, '%Y-%m-%d %H:%i') AS saat
            FROM appointments
            WHERE doktor_id = ? AND durum IN ('Bekliyor','Onaylandi') AND randevu_tarihi >= NOW()
        ");
        $stmt->execute([(int)($_GET['doktor'] ?? 0)]);
        jsonYanit($stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    if ($api === 'favoriler') {
        $stmt = $pdo->prepare("
            SELECT d.id, d.ad_soyad AS ad, d.unvan, d.poliklinik, d.hastane, d.hastane_id,
                   d.il, d.ilce, i.id AS ilce_id, i.il_id
            FROM favoriler f
            JOIN users d ON d.id = f.doktor_id AND d.aktif = 1
            JOIN hastaneler h ON h.id = d.hastane_id
            JOIN ilceler i ON i.id = h.ilce_id
            WHERE f.user_id = ?
            ORDER BY f.olusturma_tarihi DESC
            LIMIT 12
        ");
        $stmt->execute([$id]);
        jsonYanit($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($api === 'son_aramalar') {
        $stmt = $pdo->prepare("
            SELECT s.hastane_id, s.poliklinik, h.ad AS hastane,
                   i.id AS ilce_id, i.ad AS ilce, i.il_id, l.ad AS il
            FROM son_aramalar s
            JOIN hastaneler h ON h.id = s.hastane_id
            JOIN ilceler i ON i.id = h.ilce_id
            JOIN iller l ON l.id = i.il_id
            WHERE s.user_id = ?
            ORDER BY s.son_tarih DESC, s.id DESC
            LIMIT 6
        ");
        $stmt->execute([$id]);
        jsonYanit($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($api === 'oneri') {
        $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 300);
        jsonYanit(belirtiOnerileri($q));
    }

    jsonYanit(['hata' => 'Bilinmeyen istek.'], 404);
}

/* =========================================
   7. FORM ISLEMLERI
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf']) ||
        !is_string($_POST['csrf']) ||
        !hash_equals($_SESSION['csrf'], $_POST['csrf'])
    ) {
        mesajGonder('İşlem doğrulanamadı. Tekrar dene.');
    }

    $islem = $_POST['islem'] ?? '';

    /* HESAP OLUSTURMA (yalnizca hasta) */
    if ($islem === 'kayit') {
        $adKayit = trim($_POST['ad_soyad'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $parola = $_POST['parola'] ?? '';

        if (
            $adKayit === '' ||
            mb_strlen($adKayit) < 2 ||
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            strlen($parola) < 6
        ) {
            mesajGonder(
                'Ad, e-posta veya parola bilgilerini kontrol et. Parola en az 6 karakter olmalı.',
                'index.php?sayfa=kayit'
            );
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO users (ad_soyad, email, parola, rol)
                VALUES (?, ?, ?, 'hasta')
            ");
            $stmt->execute([$adKayit, $email, password_hash($parola, PASSWORD_DEFAULT)]);
            mesajGonder('Hesabın oluşturuldu. Şimdi giriş yapabilirsin.');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                mesajGonder('Bu e-posta adresi zaten kayıtlı.', 'index.php?sayfa=kayit');
            }
            mesajGonder('Hesap oluşturulamadı. Bilgileri kontrol et.', 'index.php?sayfa=kayit');
        }
    }

    /* GIRIS */
    if ($islem === 'giris') {
        $email = trim($_POST['email'] ?? '');
        $parola = $_POST['parola'] ?? '';

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $kullanici = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($kullanici && $kullanici['rol'] === 'hasta' && password_verify($parola, $kullanici['parola'])) {
            session_regenerate_id(true);
            $_SESSION['kullanici_id'] = (int)$kullanici['id'];
            $_SESSION['rol'] = $kullanici['rol'];
            $_SESSION['ad'] = $kullanici['ad_soyad'];
            header('Location: index.php');
            exit;
        }

        mesajGonder('E-posta veya parola yanlış.');
    }

    /* PAROLA SIFIRLAMA: BAGLANTI ISTE */
    if ($islem === 'sifirla_iste' && !$girisli) {
        $email = trim($_POST['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            mesajGonder('Geçerli bir e-posta adresi yaz.', 'index.php?sayfa=sifremi_unuttum');
        }

        $stmt = $pdo->prepare("SELECT id, ad_soyad FROM users WHERE email = ? AND rol = 'hasta' LIMIT 1");
        $stmt->execute([$email]);
        $k = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($k) {
            $pdo->exec("DELETE FROM parola_sifirlama WHERE son_kullanma < NOW()");
            $pdo->prepare("DELETE FROM parola_sifirlama WHERE user_id = ?")->execute([(int)$k['id']]);

            $token = bin2hex(random_bytes(32));
            $pdo->prepare("
                INSERT INTO parola_sifirlama (user_id, token_hash, son_kullanma)
                VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))
            ")->execute([(int)$k['id'], hash('sha256', $token)]);

            $taban = SITE_URL !== ''
                ? SITE_URL
                : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' .
                   ($_SERVER['HTTP_HOST'] ?? 'localhost') . (strtok($_SERVER['REQUEST_URI'] ?? '/index.php', '?') ?: '/index.php'));
            $link = $taban . '?sayfa=sifirla&token=' . $token;

            if (SIFIRLAMA_DEMO) {
                $_SESSION['sifirla_link'] = $link;
            } else {
                $govde = "Merhaba " . $k['ad_soyad'] . ",\n\nParolanı sıfırlamak için 30 dakika içinde şu bağlantıya tıkla:\n" .
                         $link . "\n\nBu isteği sen yapmadıysan bu e-postayı yok sayabilirsin.";
                @mail(
                    $email,
                    '=?UTF-8?B?' . base64_encode('RandevuPlus parola sıfırlama') . '?=',
                    $govde,
                    "Content-Type: text/plain; charset=UTF-8\r\nFrom: no-reply@randevu.local"
                );
            }
        }

        // Kayıtlı olsun olmasın aynı mesaj gösterilir (hesap varlığı sızdırılmaz).
        mesajGonder('Bu e-posta kayıtlıysa parola sıfırlama bağlantısı hazırlandı.', 'index.php?sayfa=sifremi_unuttum');
    }

    /* PAROLA SIFIRLAMA: YENI PAROLAYI KAYDET */
    if ($islem === 'sifirla_uygula' && !$girisli) {
        $token = (string)($_POST['token'] ?? '');
        $parola = (string)($_POST['parola'] ?? '');
        $parola2 = (string)($_POST['parola2'] ?? '');
        $geri = 'index.php?sayfa=sifirla&token=' . urlencode($token);

        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            mesajGonder('Bağlantı geçersiz veya süresi dolmuş.', 'index.php?sayfa=sifremi_unuttum');
        }
        if (strlen($parola) < 6) {
            mesajGonder('Parola en az 6 karakter olmalı.', $geri);
        }
        if ($parola !== $parola2) {
            mesajGonder('Parolalar eşleşmiyor.', $geri);
        }

        $stmt = $pdo->prepare("SELECT user_id FROM parola_sifirlama WHERE token_hash = ? AND son_kullanma > NOW() LIMIT 1");
        $stmt->execute([hash('sha256', $token)]);
        $kayit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$kayit) {
            mesajGonder('Bağlantı geçersiz veya süresi dolmuş. Yeni bir bağlantı iste.', 'index.php?sayfa=sifremi_unuttum');
        }

        $pdo->beginTransaction();
        $pdo->prepare("UPDATE users SET parola = ? WHERE id = ? AND rol = 'hasta'")
            ->execute([password_hash($parola, PASSWORD_DEFAULT), (int)$kayit['user_id']]);
        $pdo->prepare("DELETE FROM parola_sifirlama WHERE user_id = ?")->execute([(int)$kayit['user_id']]);
        $pdo->commit();

        mesajGonder('Parolan güncellendi. Şimdi yeni parolanla giriş yapabilirsin.');
    }

    /* GIRIS YAPMAYANLAR DIGER ISLEMLERI YAPAMAZ */
    if (!$girisli) {
        mesajGonder('Önce giriş yapmalısın.');
    }

    /* HASTA RANDEVU OLUSTURUR */
    if ($islem === 'randevu_olustur' && $rol === 'hasta') {
        $doktorId = (int)($_POST['doktor_id'] ?? 0);
        $tarihGirdi = $_POST['randevu_tarihi'] ?? '';
        $aciklama = trim($_POST['aciklama'] ?? '');

        $tarih = DateTime::createFromFormat('Y-m-d\TH:i', $tarihGirdi);
        $simdi = new DateTime();

        if (!$tarih || $tarih <= $simdi) {
            mesajGonder('Lütfen gelecekte bir tarih ve saat seç.');
        }
        if ($tarih > (new DateTime())->modify('+60 days')) {
            mesajGonder('En fazla 60 gün sonrasına randevu alınabilir.');
        }

        if ((int)$tarih->format('N') >= 6) {
            mesajGonder('Hafta sonu randevu alınamaz. Lütfen pazartesi-cuma arasında bir gün seç.');
        }
        $saat = $tarih->format('H:i:s');
        if ($saat < '08:00:00' || $saat >= '17:00:00' || !in_array($tarih->format('i'), ['00', '30'], true)) {
            mesajGonder('Randevu saatleri 08.00-17.00 arasındadır. Lütfen listeden bir saat seç.');
        }
        if ($saat >= '12:00:00' && $saat < '13:00:00') {
            mesajGonder('12.00-13.00 arası öğle arasıdır. Başka bir saat seç.');
        }
        if (mb_strlen($aciklama) > 500) {
            mesajGonder('Açıklama en fazla 500 karakter olabilir.');
        }

        $stmt = $pdo->prepare("
            SELECT id, mesai_baslangic, mesai_bitis
            FROM users WHERE id = ? AND rol = 'doktor' AND aktif = 1
        ");
        $stmt->execute([$doktorId]);
        $doktorSecimi = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$doktorSecimi) {
            mesajGonder('Lütfen geçerli bir doktor seç.');
        }
        if (
            empty($doktorSecimi['mesai_baslangic']) ||
            empty($doktorSecimi['mesai_bitis']) ||
            $saat < $doktorSecimi['mesai_baslangic'] ||
            $saat >= $doktorSecimi['mesai_bitis']
        ) {
            mesajGonder('Seçtiğin saat doktorun çalışma saatleri dışında. Doktorun çalışma saatleri içinde bir saat seç.');
        }

        $tarihSql = $tarih->format('Y-m-d H:i:s');

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT id FROM appointments
            WHERE doktor_id = ? AND randevu_tarihi = ? AND durum IN ('Bekliyor', 'Onaylandi')
            LIMIT 1 FOR UPDATE
        ");
        $stmt->execute([$doktorId, $tarihSql]);
        if ($stmt->fetch()) {
            $pdo->rollBack();
            mesajGonder('Bu doktorun o saatte randevusu var. Başka saat seç.');
        }

        $stmt = $pdo->prepare("
            SELECT id FROM appointments
            WHERE hasta_id = ? AND randevu_tarihi = ? AND durum IN ('Bekliyor', 'Onaylandi')
            LIMIT 1
        ");
        $stmt->execute([$id, $tarihSql]);
        if ($stmt->fetch()) {
            $pdo->rollBack();
            mesajGonder('Bu saatte zaten başka bir randevun var.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO appointments (hasta_id, doktor_id, randevu_tarihi, aciklama)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$id, $doktorId, $tarihSql, $aciklama]);
        $yeniRandevuId = (int)$pdo->lastInsertId();

        $bildirim = $pdo->prepare("INSERT INTO notifications (user_id, appointment_id, mesaj) VALUES (?, ?, ?)");
        $bildirim->execute([$id, $yeniRandevuId, 'Randevunuz oluşturuldu. 1 dakika içinde otomatik onaylanacak.']);
        $pdo->commit();

        mesajGonder('Randevun oluşturuldu. 1 dakika sonra otomatik onaylanacak.');
    }

    /* HASTA RANDEVU IPTAL EDER */
    if ($islem === 'randevu_iptal' && $rol === 'hasta') {
        $randevuId = (int)($_POST['randevu_id'] ?? 0);

        $stmt = $pdo->prepare("
            UPDATE appointments SET durum = 'Iptal'
            WHERE id = ? AND hasta_id = ? AND durum IN ('Bekliyor', 'Onaylandi')
        ");
        $stmt->execute([$randevuId, $id]);
        if ($stmt->rowCount()) {
            $bildirim = $pdo->prepare("INSERT INTO notifications (user_id, appointment_id, mesaj) VALUES (?, ?, ?)");
            $bildirim->execute([$id, $randevuId, 'Randevunuz iptal edildi.']);
        }

        mesajGonder('Randevu durumu güncellendi.');
    }

    if ($islem === 'bildirimleri_oku' && $rol === 'hasta') {
        $stmt = $pdo->prepare('UPDATE notifications SET okundu=1 WHERE user_id=?');
        $stmt->execute([$id]);
        mesajGonder('Bildirimlerin okundu olarak işaretlendi.');
    }

    mesajGonder('Geçersiz işlem.');
}

/* =========================================
   8. SAYFA VERILERI
========================================= */

$mesaj = $_SESSION['mesaj'] ?? '';
unset($_SESSION['mesaj']);

$sayfa = $_GET['sayfa'] ?? 'giris';

// Parola sıfırlama sayfası verileri
$sifirlaLink = (string)($_SESSION['sifirla_link'] ?? '');
unset($_SESSION['sifirla_link']);
$sifirlaToken = '';
$sifirlaGecerli = false;
if ($sayfa === 'sifirla') {
    $t = (string)($_GET['token'] ?? '');
    if (preg_match('/^[a-f0-9]{64}$/', $t)) {
        $stmt = $pdo->prepare("SELECT 1 FROM parola_sifirlama WHERE token_hash = ? AND son_kullanma > NOW() LIMIT 1");
        $stmt->execute([hash('sha256', $t)]);
        $sifirlaGecerli = (bool)$stmt->fetchColumn();
        if ($sifirlaGecerli) $sifirlaToken = $t;
    }
}

$illerListe = [];
$randevular = [];
$bildirimler = [];
$benimSaatler = [];
$okunmamisBildirim = 0;
$sayac = ['Bekliyor' => 0, 'Onaylandi' => 0, 'Tamamlandi' => 0, 'Iptal' => 0];
$sonraki = null;
$genelIst = ['il' => 0, 'ilce' => 0, 'hastane' => 0, 'doktor' => 0, 'poliklinik' => 0];

if (!$girisli) {
    $genelIst['il'] = (int)$pdo->query("SELECT COUNT(*) FROM iller")->fetchColumn();
    $genelIst['ilce'] = (int)$pdo->query("SELECT COUNT(*) FROM ilceler")->fetchColumn();
    $satir = $pdo->query("SELECT COUNT(*) AS hastane, COALESCE(SUM(doktor_sayisi),0) AS doktor FROM hastaneler")->fetch(PDO::FETCH_ASSOC);
    $genelIst['hastane'] = (int)$satir['hastane'];
    $genelIst['doktor'] = (int)$satir['doktor'];
    $genelIst['poliklinik'] = count(array_unique(array_merge(...array_values(poliklinikGruplari()))));
}

if ($girisli && $rol === 'hasta') {
    $illerListe = $pdo->query("SELECT id, ad FROM iller ORDER BY ad")->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT a.*, d.ad_soyad AS doktor_adi, d.unvan, d.uzmanlik,
               d.il, d.ilce, d.hastane, d.poliklinik
        FROM appointments a
        JOIN users d ON d.id = a.doktor_id
        WHERE a.hasta_id = ?
        ORDER BY a.randevu_tarihi DESC
    ");
    $stmt->execute([$id]);
    $randevular = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($randevular as $k => $r) {
        $sayac[$r['durum']] = ($sayac[$r['durum']] ?? 0) + 1;
        $aktifMi = in_array($r['durum'], ['Bekliyor', 'Onaylandi'], true);
        $gelecek = strtotime($r['randevu_tarihi']) > time();
        if ($r['durum'] === 'Iptal')      $randevular[$k]['grup'] = 'iptal';
        elseif ($aktifMi && $gelecek)     $randevular[$k]['grup'] = 'yaklasan';
        else                              $randevular[$k]['grup'] = 'gecmis';

        if ($aktifMi) {
            $benimSaatler[] = date('Y-m-d H:i', strtotime($r['randevu_tarihi']));
        }
        if ($aktifMi && $gelecek && (!$sonraki || strtotime($r['randevu_tarihi']) < strtotime($sonraki['randevu_tarihi']))) {
            $sonraki = $r;
        }
    }

    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY olusturma_tarihi DESC LIMIT 20");
    $stmt->execute([$id]);
    $bildirimler = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND okundu=0");
    $stmt->execute([$id]);
    $okunmamisBildirim = (int)$stmt->fetchColumn();
}

$saatDilimi = (int)date('G');
$selam = $saatDilimi < 12 ? 'Günaydın' : ($saatDilimi < 18 ? 'İyi günler' : 'İyi akşamlar');
$jsFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>RandevuPlus · Online Randevu Sistemi</title>

<script>
// Tema, sayfa çizilmeden önce uygulanır (yanıp sönmeyi önler).
(function () {
    try {
        var t = localStorage.getItem('tema');
        if (t !== 'dark' && t !== 'light') t = window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', t);
    } catch (e) { document.documentElement.setAttribute('data-theme', 'light'); }
})();
</script>

<style>
:root {
    --mor: #7c3aed;
    --mor-a: #8b5cf6;
    --koyu: #5b21b6;
    --pembe: #ec4899;
    --teal: #0d9488;
    --metin: #2a2145;
    --soluk: #6f6a85;
    --kenar: #ebe5fa;
    --zemin: #f6f2ff;
    --cam: rgba(255,255,255,.78);
    --golge: 0 14px 40px rgba(91,33,182,.10);
    --ekg1: #8b5cf6;
    --ekg2: #ec4899;
    --ekg3: #0d9488;
}
* { box-sizing: border-box; }
html { scroll-behavior: smooth; }
body {
    margin: 0;
    font-family: 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
    color: var(--metin);
    min-height: 100vh;
    background: var(--zemin);
}
a { color: var(--koyu); text-decoration: none; }
button, input, select, textarea { font: inherit; color: inherit; }
button, .buton {
    border: 0; cursor: pointer; border-radius: 13px; padding: 12px 18px;
    font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    transition: transform .15s ease, box-shadow .15s ease, opacity .15s ease;
}
button:hover:not(:disabled), .buton:hover { transform: translateY(-1px); }
button:disabled { opacity: .5; cursor: not-allowed; }
.birincil { background: linear-gradient(135deg, var(--mor-a), var(--pembe)); color: #fff; box-shadow: 0 8px 20px rgba(139,92,246,.30); }
.ikincil { background: #f1eaff; color: var(--koyu); }
.sil { background: #ffe4ec; color: #be123c; }
.tam { width: 100%; }

/* ---------- ARKA PLAN (animasyonlu, geçişli) ---------- */
.arka-plan {
    position: fixed; inset: 0; z-index: -1; overflow: hidden;
    background: linear-gradient(120deg, #faf5ff, #ede9fe, #fce7f3, #e0f2fe, #ccfbf1, #faf5ff);
    background-size: 300% 300%;
    animation: renkGecisi 12s ease-in-out infinite;
}
@keyframes renkGecisi {
    0%   { background-position: 0% 0%; }
    50%  { background-position: 100% 100%; }
    100% { background-position: 0% 0%; }
}

/* Ekranı çaprazlama süpüren yumuşak ışık bandı */
.arka-plan::before {
    content: ""; position: absolute; inset: -50%; z-index: 0;
    background: linear-gradient(115deg, transparent 40%, rgba(255,255,255,.55) 50%, transparent 60%);
    animation: isikSupur 9s ease-in-out infinite;
}
@keyframes isikSupur {
    0%   { transform: translateX(-40%); }
    100% { transform: translateX(40%); }
}

/* Yavaşça kayan artı deseni */
.arka-plan::after {
    content: ""; position: absolute; inset: 0;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='90' height='90'%3E%3Cpath d='M45 33v24M33 45h24' stroke='%238b5cf6' stroke-opacity='.10' stroke-width='4' stroke-linecap='round'/%3E%3Ccircle cx='8' cy='8' r='2.5' fill='%23ec4899' fill-opacity='.12'/%3E%3C/svg%3E");
    animation: desenKay 16s linear infinite;
}
@keyframes desenKay { to { background-position: 90px 90px; } }

/* Dönen, şekil değiştiren renkli ışık lekeleri */
.blob {
    position: absolute; filter: blur(70px); opacity: .55;
    border-radius: 42% 58% 60% 40% / 50% 40% 60% 50%;
    animation: yuz 10s ease-in-out infinite, sekil 7s ease-in-out infinite;
    will-change: transform;
}
.b1 { width: 520px; height: 520px; background: #c4b5fd; top: -140px; left: -120px; }
.b2 { width: 440px; height: 440px; background: #fbcfe8; top: 28%; right: -140px; animation-delay: -7s, -3s; }
.b3 { width: 400px; height: 400px; background: #99f6e4; bottom: -140px; left: 28%; opacity: .38; animation-delay: -13s, -6s; }
.b4 { width: 340px; height: 340px; background: #bae6fd; top: 8%; left: 42%; opacity: .40; animation-duration: 13s, 9s; animation-delay: -4s, -5s; }
.b5 { width: 300px; height: 300px; background: #fde68a; bottom: 6%; right: 12%; opacity: .30; animation-duration: 15s, 8s; animation-delay: -10s, -2s; }
@keyframes yuz {
    0%, 100% { transform: translate(0, 0) scale(1) rotate(0deg); }
    25%      { transform: translate(18vw, -10vh) scale(1.25) rotate(90deg); }
    50%      { transform: translate(-8vw, 16vh) scale(.9) rotate(180deg); }
    75%      { transform: translate(-20vw, -6vh) scale(1.15) rotate(270deg); }
}
@keyframes sekil {
    0%, 100% { border-radius: 42% 58% 60% 40% / 50% 40% 60% 50%; }
    50%      { border-radius: 60% 40% 38% 62% / 42% 60% 40% 58%; }
}

/* Yukarı süzülen yarı saydam artılar */
.yuzen-arti { position: absolute; inset: 0; pointer-events: none; }
.yuzen-arti span {
    position: absolute; bottom: -60px; left: var(--x);
    font-size: var(--s); color: #8b5cf6; opacity: 0;
    animation: suzul var(--d) linear infinite; animation-delay: var(--g);
}
.yuzen-arti span:nth-child(even) { color: #ec4899; }
@keyframes suzul {
    0%   { transform: translate(0, 0) rotate(0deg); opacity: 0; }
    10%  { opacity: .32; }
    25%  { transform: translate(30px, -28vh) rotate(90deg); }
    50%  { transform: translate(-30px, -57vh) rotate(180deg); }
    75%  { transform: translate(30px, -86vh) rotate(270deg); }
    90%  { opacity: .32; }
    100% { transform: translate(0, -115vh) rotate(360deg); opacity: 0; }
}

/* Panel (giriş yapılmış): daha sakin arka plan, form doldururken dikkat dağıtmaz */
.panel .arka-plan { animation-duration: 45s; }
.panel .arka-plan::before { animation-duration: 28s; opacity: .45; }
.panel .arka-plan::after { animation-duration: 70s; }
.panel .blob { animation-duration: 32s, 22s; opacity: .32; }
.panel .yuzen-arti { opacity: .35; }
.panel .yuzen-arti span { animation-duration: 40s; }

/* Hareketi azaltmak isteyenler için */
@media (prefers-reduced-motion: reduce) {
    .arka-plan, .arka-plan::before, .arka-plan::after, .blob, .yuzen-arti span { animation: none; }
}

/* ---------- YAPI ---------- */
.container { width: min(1180px, 94%); margin: 0 auto; }
.topbar {
    position: sticky; top: 10px; z-index: 20; margin-top: 12px;
    display: flex; justify-content: space-between; align-items: center; gap: 15px;
    padding: 12px 20px; border-radius: 20px;
    background: var(--cam); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
    border: 1px solid rgba(255,255,255,.9); box-shadow: var(--golge);
}
.logo { display: flex; align-items: center; gap: 10px; font-size: 22px; font-weight: 800; color: var(--koyu); }
.logo .ikon {
    width: 38px; height: 38px; border-radius: 12px; display: grid; place-items: center; color: #fff; font-size: 20px;
    background: linear-gradient(135deg, var(--mor-a), var(--pembe));
}
.logo span.pl { color: var(--pembe); }
.ust-yazi { color: var(--soluk); font-size: 14px; }
.menu { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.avatar { width: 38px; height: 38px; border-radius: 50%; display: grid; place-items: center; font-weight: 800; color: #fff; background: linear-gradient(135deg, var(--teal), var(--mor-a)); }
.icerik { padding: 28px 0 50px; }
.kart {
    background: var(--cam); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
    border: 1px solid rgba(255,255,255,.95); border-radius: 24px; padding: 26px;
    box-shadow: var(--golge); margin-bottom: 22px;
}
.kart h2, .kart h3 { margin-top: 0; }
.alt-baslik { color: var(--soluk); line-height: 1.7; margin-top: 0; }

/* ---------- BILDIRIM TOAST ---------- */
.mesaj {
    position: fixed; top: 86px; right: 20px; z-index: 50; max-width: min(380px, 90vw);
    padding: 14px 18px; border-radius: 14px; background: #fff; color: #5b21b6; line-height: 1.5;
    border-left: 5px solid var(--mor-a); box-shadow: 0 16px 40px rgba(91,33,182,.22);
    animation: gir .35s ease; display: flex; gap: 12px; align-items: flex-start;
}
.mesaj button { background: none; padding: 0; font-size: 18px; color: var(--soluk); }
@keyframes gir { from { opacity: 0; transform: translateY(-12px); } to { opacity: 1; transform: none; } }

/* ---------- GIRIS EKRANI ---------- */
.giris-grid { display: grid; grid-template-columns: 1.1fr .9fr; gap: 28px; align-items: center; padding: 34px 0 20px; }
.tanitim {
    position: relative; overflow: hidden; border-radius: 30px; color: #fff;
    padding: clamp(28px, 5vw, 54px); min-height: 520px;
    background: linear-gradient(145deg, #7c3aed 0%, #a855f7 45%, #ec4899 100%);
    background-size: 200% 200%;
    animation: tanitimGecis 14s ease-in-out infinite;
    box-shadow: 0 30px 70px rgba(124,58,237,.35);
    display: flex; flex-direction: column; justify-content: center;
}
@keyframes tanitimGecis {
    0%, 100% { background-position: 0% 50%; }
    50%      { background-position: 100% 50%; }
}
.tanitim::before, .tanitim::after { content: ""; position: absolute; border-radius: 50%; background: rgba(255,255,255,.12); }
.tanitim::before { width: 320px; height: 320px; right: -100px; top: -100px; animation: halka 12s ease-in-out infinite; }
.tanitim::after { width: 220px; height: 220px; left: -70px; bottom: -70px; animation: halka 16s ease-in-out infinite reverse; }
@keyframes halka {
    0%, 100% { transform: scale(1) translate(0, 0); }
    50%      { transform: scale(1.15) translate(-16px, 14px); }
}
.tanitim > * { position: relative; z-index: 1; }
.rozet { display: inline-block; background: rgba(255,255,255,.2); padding: 7px 14px; border-radius: 30px; font-size: 13px; font-weight: 700; margin-bottom: 18px; width: fit-content; }
.tanitim h1 { font-size: clamp(30px, 4.2vw, 46px); line-height: 1.15; margin: 0 0 16px; }
.tanitim p { line-height: 1.8; opacity: .95; max-width: 520px; }
.ist-satir { display: flex; gap: 14px; flex-wrap: wrap; margin: 22px 0; }
.ist-kutu { background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.3); border-radius: 18px; padding: 14px 18px; min-width: 105px; }
.ist-kutu b { display: block; font-size: 26px; }
.ist-kutu span { font-size: 12px; opacity: .9; }
.ozellik { margin-top: 10px; font-weight: 600; }
.yuzen-kart {
    position: absolute; right: 28px; bottom: 30px; background: #fff; color: var(--metin);
    border-radius: 18px; padding: 14px 16px; width: 230px; box-shadow: 0 20px 40px rgba(0,0,0,.2);
    animation: sallan 5s ease-in-out infinite;
}
.yuzen-kart small { color: var(--soluk); display: block; }
.yuzen-kart .ok { color: #16a34a; font-weight: 800; font-size: 12px; margin-top: 6px; }
@keyframes sallan { 0%,100% { transform: translateY(0) rotate(-1deg); } 50% { transform: translateY(-10px) rotate(1deg); } }
.form-kart { width: 100%; }
.form-grup { margin: 15px 0; }
.form-grup label { display: block; margin-bottom: 8px; font-weight: 700; font-size: 14px; }
.form-grup input, .form-grup select, .form-grup textarea {
    width: 100%; border: 1.5px solid #e6ddf4; border-radius: 13px; padding: 13px 14px;
    background: #fdfcff; outline: none; transition: border-color .15s, box-shadow .15s;
}
.form-grup input:focus, .form-grup select:focus, .form-grup textarea:focus {
    border-color: var(--mor-a); box-shadow: 0 0 0 4px rgba(139,92,246,.14);
}
.form-grup select:disabled { background: #f4f1fa; color: #aaa4bd; cursor: not-allowed; }
.form-grup textarea { min-height: 90px; resize: vertical; }

/* ---------- HASTA PANELI ---------- */
.karsilama { display: grid; grid-template-columns: 1.2fr .8fr; gap: 20px; margin-bottom: 22px; }
.hos {
    border-radius: 26px; padding: 30px; color: #fff; position: relative; overflow: hidden;
    background: linear-gradient(135deg, #7c3aed, #ec4899, #a855f7);
    background-size: 200% 200%;
    animation: tanitimGecis 14s ease-in-out infinite;
    box-shadow: 0 24px 55px rgba(124,58,237,.30);
}
.hos::after { content: ""; position: absolute; width: 240px; height: 240px; border-radius: 50%; background: rgba(255,255,255,.13); right: -60px; top: -80px; animation: halka 12s ease-in-out infinite; }
.hos h1 { margin: 0 0 8px; font-size: clamp(24px, 3.5vw, 34px); position: relative; z-index: 1; }
.hos p { margin: 0 0 18px; opacity: .93; line-height: 1.7; position: relative; z-index: 1; }
.hos .buton { background: #fff; color: var(--koyu); position: relative; z-index: 1; }
.sonraki { background: var(--cam); backdrop-filter: blur(12px); border: 1px solid #fff; border-radius: 26px; padding: 24px; box-shadow: var(--golge); }
.sonraki .etiket-ust { font-size: 12px; font-weight: 800; letter-spacing: .06em; color: var(--pembe); text-transform: uppercase; margin-bottom: 10px; }
.sonraki h3 { margin: 0 0 4px; }
.sonraki .zaman { font-size: 22px; font-weight: 800; color: var(--koyu); margin: 10px 0 4px; }
.grid-4 { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 16px; margin-bottom: 22px; }
.istatistik {
    padding: 20px; border-radius: 20px; background: var(--cam); backdrop-filter: blur(12px);
    border: 1px solid #fff; box-shadow: var(--golge); display: flex; align-items: center; gap: 14px;
}
.istatistik .ikon-kutu { width: 48px; height: 48px; border-radius: 15px; display: grid; place-items: center; font-size: 22px; flex: none; }
.ik1 { background: #ede9fe; } .ik2 { background: #fef3c7; } .ik3 { background: #dcfce7; } .ik4 { background: #dbeafe; }
.istatistik .sayi { font-size: 28px; font-weight: 800; color: var(--koyu); line-height: 1; }
.istatistik .etiket { color: var(--soluk); font-size: 13px; margin-top: 5px; }

details.bildirim-kart summary { cursor: pointer; font-weight: 800; font-size: 18px; list-style: none; display: flex; align-items: center; gap: 10px; }
details.bildirim-kart summary::-webkit-details-marker { display: none; }
.bildirim { padding: 12px 14px; border-radius: 12px; background: #fff7fb; border: 1px solid #fce7f3; margin: 8px 0; font-size: 14px; }
.bildirim.yeni { border-color: #f9a8d4; background: #fdf2f8; }

/* ---------- RANDEVU SIHIRBAZI ---------- */
.adimlar { display: flex; gap: 8px; margin: 18px 0 8px; flex-wrap: wrap; }
.adim { display: flex; align-items: center; gap: 8px; padding: 8px 14px; border-radius: 30px; background: #f4f0fb; color: var(--soluk); font-size: 13px; font-weight: 700; transition: background .3s, color .3s; }
.adim .no { width: 22px; height: 22px; border-radius: 50%; display: grid; place-items: center; background: #e2d9f3; color: #fff; font-size: 12px; transition: background .3s; }
.adim.aktif { background: #ede9fe; color: var(--koyu); }
.adim.aktif .no { background: var(--mor-a); }
.adim.tamam { background: #dcfce7; color: #166534; }
.adim.tamam .no { background: #22c55e; }
.adim-blok { border: 1px solid var(--kenar); background: rgba(255,255,255,.7); border-radius: 20px; padding: 20px; margin-top: 16px; }
.adim-blok h3 { display: flex; align-items: center; gap: 10px; font-size: 17px; margin-bottom: 14px; }
.adim-blok h3 .no { width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center; background: linear-gradient(135deg, var(--mor-a), var(--pembe)); color: #fff; font-size: 13px; }
.konum-grid { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 14px; }
.konum-grid .form-grup { margin: 0; }
.konum-bilgi { margin-top: 10px; }

.arama { margin-bottom: 12px; }
.arama input { width: 100%; border: 1.5px solid #e6ddf4; border-radius: 13px; padding: 12px 14px; background: #fff; outline: none; }
.arama input:focus { border-color: var(--mor-a); }
.doktor-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; max-height: 440px; overflow-y: auto; padding: 4px; }
.doktor-kart {
    display: flex; gap: 12px; align-items: center; text-align: left; background: #fff;
    border: 1.5px solid #ece6fb; border-radius: 16px; padding: 14px; color: var(--metin); font-weight: 400;
}
.doktor-kart:hover { border-color: var(--pembe); box-shadow: 0 8px 22px rgba(236,72,153,.12); }
.doktor-kart.secili { border-color: var(--mor-a); background: #f5f0ff; box-shadow: 0 0 0 3px rgba(139,92,246,.18); }
.d-avatar { width: 48px; height: 48px; border-radius: 15px; flex: none; display: grid; place-items: center; font-weight: 800; color: #fff; background: linear-gradient(135deg, var(--mor-a), var(--pembe)); }
.d-ad { font-weight: 800; font-size: 15px; }
.d-alt { color: var(--soluk); font-size: 12.5px; line-height: 1.5; }
.d-saat { display: inline-block; margin-top: 4px; background: #ccfbf1; color: #0f766e; border-radius: 20px; padding: 2px 9px; font-size: 11.5px; font-weight: 700; }

.saat-grup-baslik { font-weight: 800; font-size: 13px; color: var(--soluk); margin: 14px 0 8px; letter-spacing: .03em; }
.saat-grid { display: grid; grid-template-columns: repeat(6, minmax(0,1fr)); gap: 9px; }
.saat-btn { padding: 11px 4px; border: 1.5px solid #e9d5ff; border-radius: 12px; background: #fff; color: var(--koyu); font-weight: 700; }
.saat-btn:hover:not(:disabled) { background: #fdf2f8; border-color: var(--pembe); }
.saat-btn.secili { background: linear-gradient(135deg, var(--mor-a), var(--pembe)); color: #fff; border-color: transparent; }
.saat-btn.dolu { background: #f1f1f4; color: #aaa; border-color: #e5e5e9; text-decoration: line-through; }
.ozet {
    margin-top: 14px; padding: 16px 18px; border-radius: 16px; line-height: 1.8; font-size: 14.5px;
    background: linear-gradient(135deg, #f5f3ff, #fdf2f8); border: 1px dashed #c4b5fd;
}
.bos { padding: 25px; text-align: center; color: var(--soluk); background: #faf8ff; border-radius: 15px; }

/* ---------- RANDEVULARIM ---------- */
.sekmeler { display: flex; gap: 8px; flex-wrap: wrap; margin: 14px 0 18px; }
.sekme { background: #f4f0fb; color: var(--soluk); padding: 9px 16px; border-radius: 30px; font-size: 14px; }
.sekme.aktif { background: linear-gradient(135deg, var(--mor-a), var(--pembe)); color: #fff; }
.randevu-liste { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 16px; }
.randevu { display: flex; gap: 16px; padding: 18px; border: 1px solid var(--kenar); border-radius: 20px; background: #fff; transition: box-shadow .2s, transform .2s; }
.randevu:hover { box-shadow: 0 14px 30px rgba(91,33,182,.10); transform: translateY(-2px); }
.tarih-blok { flex: none; width: 70px; text-align: center; border-radius: 16px; padding: 10px 4px; background: linear-gradient(160deg, #ede9fe, #fce7f3); height: fit-content; }
.tarih-blok .gun { font-size: 28px; font-weight: 800; color: var(--koyu); line-height: 1; }
.tarih-blok .ay { font-size: 12px; font-weight: 800; color: var(--pembe); text-transform: uppercase; margin-top: 3px; }
.tarih-blok .saat { font-size: 13px; font-weight: 700; margin-top: 8px; color: var(--metin); }
.randevu-govde { flex: 1; min-width: 0; }
.randevu-baslik { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
.randevu h3 { margin: 0 0 6px; font-size: 16.5px; }
.detay { color: var(--soluk); font-size: 13.5px; line-height: 1.75; }
.randevu-no { font-size: 11.5px; color: #a19bb8; letter-spacing: .04em; margin-top: 6px; }
.durum { display: inline-block; border-radius: 20px; padding: 6px 11px; font-size: 12px; font-weight: 800; background: #fef3c7; color: #92400e; white-space: nowrap; }
.durum.Onaylandi { background: #dcfce7; color: #166534; }
.durum.Tamamlandi { background: #dbeafe; color: #1d4ed8; }
.durum.Iptal { background: #ffe4e6; color: #be123c; }
.islem-formu { margin-top: 12px; }
.islem-formu .sil { padding: 8px 14px; font-size: 13px; }
.alt { text-align: center; padding: 26px; color: var(--soluk); font-size: 13px; }

/* ---------- FAVORILER, SON ARAMALAR, ONERI, PAROLA ---------- */
.unuttum { display: inline-block; margin-top: 8px; font-size: 13px; font-weight: 700; }
.sifirla-kutu { margin: 16px 0; padding: 16px 18px; border-radius: 16px; background: linear-gradient(135deg, #f5f3ff, #fdf2f8); border: 1px dashed #c4b5fd; }
.sifirla-kutu p { margin: 6px 0 12px; color: var(--soluk); font-size: 14px; line-height: 1.6; }
.hizli-erisim { margin-top: 16px; padding: 16px 18px; border-radius: 20px; border: 1px solid var(--kenar); background: rgba(255,255,255,.7); }
.hizli-erisim > div + div { margin-top: 14px; }
.hizli-baslik { font-weight: 800; font-size: 14px; margin-bottom: 10px; display: flex; align-items: center; gap: 10px; }
.link-btn { background: none; padding: 0; font-size: 12.5px; color: var(--soluk); text-decoration: underline; font-weight: 600; }
.chip-liste { display: flex; flex-wrap: wrap; gap: 10px; }
.chip { display: inline-flex; align-items: center; gap: 10px; text-align: left; background: #fff; border: 1.5px solid #ece6fb; border-radius: 14px; padding: 9px 13px; color: var(--metin); font-weight: 400; max-width: 100%; }
.chip:hover { border-color: var(--pembe); box-shadow: 0 8px 20px rgba(236,72,153,.12); }
.chip b { display: block; font-size: 14px; }
.chip small { display: block; color: var(--soluk); font-size: 12px; line-height: 1.4; }
.chip-ik { font-size: 18px; }
.doktor-kart { cursor: pointer; }
.d-bilgi { min-width: 0; }
.fav-btn { margin-left: auto; flex: none; width: 36px; height: 36px; padding: 0; border-radius: 50%; background: #f4f0fb; color: #a19bb8; font-size: 18px; line-height: 1; }
.fav-btn.acik { background: #fef3c7; color: #f59e0b; }
.oneri-kutu { margin-bottom: 18px; padding: 16px; border-radius: 16px; background: linear-gradient(135deg, #f5f3ff, #fdf2f8); border: 1px dashed #c4b5fd; }
.oneri-kutu > label { display: block; font-weight: 700; font-size: 14px; margin-bottom: 10px; }
.oneri-satir { display: flex; gap: 10px; }
.oneri-satir input { flex: 1; min-width: 0; border: 1.5px solid #e6ddf4; border-radius: 13px; padding: 12px 14px; background: #fff; outline: none; }
.oneri-satir input:focus { border-color: var(--mor-a); box-shadow: 0 0 0 4px rgba(139,92,246,.14); }
.oneri-sonuc { margin-top: 14px; }
.oneri-baslik { font-weight: 800; font-size: 13px; color: var(--soluk); margin-bottom: 8px; letter-spacing: .03em; }
.oneri-chipler { display: flex; flex-wrap: wrap; gap: 10px; }
.oneri-chip { border-color: #c4b5fd; }
.oneri-acil { margin-bottom: 12px; padding: 12px 14px; border-radius: 12px; background: #ffe4e6; color: #9f1239; font-weight: 700; line-height: 1.6; border: 1px solid #fda4af; }
.oneri-uyari { margin-top: 10px; font-size: 12.5px; color: var(--soluk); line-height: 1.6; }
@media (max-width: 560px) { .oneri-satir { flex-direction: column; } }

/* ---------- GIRIS ANIMASYONU (kalp dolar + EKG cizilir) ---------- */
.giris-yukleme {
    position: fixed; inset: 0; z-index: 100; display: grid; place-items: center;
    background: rgba(246,242,255,.86); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
    opacity: 0; visibility: hidden; transition: opacity .35s ease, visibility .35s ease;
}
.giris-yukleme.acik { opacity: 1; visibility: visible; }
.gy-kutu { text-align: center; padding: 20px; }
.gy-kalp { width: 150px; height: 135px; overflow: visible; filter: drop-shadow(0 14px 30px rgba(239,68,68,.45)); animation: kalpAtis 1s ease-in-out infinite; }
@keyframes kalpAtis {
    0%, 100% { transform: scale(1); }
    14%      { transform: scale(1.09); }
    28%      { transform: scale(1); }
    42%      { transform: scale(1.05); }
    56%      { transform: scale(1); }
}
.gy-ekg { display: block; width: min(420px, 80vw); height: auto; margin: 12px auto 0; overflow: visible; filter: drop-shadow(0 0 6px rgba(239,68,68,.6)); }
.gy-yuzde { font-size: 30px; font-weight: 800; color: var(--koyu); margin-top: 10px; }
.gy-metin { color: var(--soluk); font-weight: 600; margin-top: 4px; min-height: 22px; }
:root[data-theme="dark"] .giris-yukleme { background: rgba(13,8,32,.88); }
@media (prefers-reduced-motion: reduce) { .gy-kalp { animation: none; } }

/* ---------- TEMA DUGMESI ---------- */
.tema-btn { width: 42px; height: 42px; padding: 0; border-radius: 50%; font-size: 18px; }
html.tema-gecis * { transition: background-color .4s ease, color .4s ease, border-color .4s ease, box-shadow .4s ease !important; }

/* Panelde kartlar daha sakin */
.panel .hos, .panel .hos::after { animation-duration: 30s; }

/* ---------- KOYU TEMA ---------- */
:root[data-theme="dark"] {
    color-scheme: dark;
    --koyu: #c4b5fd;
    --metin: #ece8fb;
    --soluk: #a9a3c4;
    --kenar: #3a3358;
    --zemin: #0f0a22;
    --cam: rgba(32,25,60,.72);
    --golge: 0 14px 40px rgba(0,0,0,.45);
    --ekg1: #a78bfa;
    --ekg2: #f472b6;
    --ekg3: #2dd4bf;
}
:root[data-theme="dark"] .arka-plan {
    background-image: linear-gradient(120deg, #0d0820, #1a1038, #2a0f3a, #0b1f3a, #0a2a2e, #0d0820);
}
:root[data-theme="dark"] .arka-plan::before { background: linear-gradient(115deg, transparent 40%, rgba(167,139,250,.14) 50%, transparent 60%); }
:root[data-theme="dark"] .arka-plan::after { opacity: .55; }
:root[data-theme="dark"] .blob { opacity: .45; }
:root[data-theme="dark"] .panel .blob { opacity: .28; }
:root[data-theme="dark"] .b1 { background: #6d28d9; }
:root[data-theme="dark"] .b2 { background: #be185d; }
:root[data-theme="dark"] .b3 { background: #0f766e; }
:root[data-theme="dark"] .b4 { background: #1d4ed8; }
:root[data-theme="dark"] .b5 { background: #b45309; }
:root[data-theme="dark"] .yuzen-arti span { opacity: 0; }

:root[data-theme="dark"] .topbar,
:root[data-theme="dark"] .kart,
:root[data-theme="dark"] .sonraki,
:root[data-theme="dark"] .istatistik { border-color: rgba(255,255,255,.08); }
:root[data-theme="dark"] .ikincil { background: rgba(139,92,246,.22); color: #ddd6fe; }
:root[data-theme="dark"] .sil { background: rgba(244,63,94,.18); color: #fda4af; }
:root[data-theme="dark"] .hos .buton { background: #fff; color: #5b21b6; }

:root[data-theme="dark"] .form-grup input,
:root[data-theme="dark"] .form-grup select,
:root[data-theme="dark"] .form-grup textarea,
:root[data-theme="dark"] .arama input { background: rgba(255,255,255,.06); border-color: #3a3358; color: var(--metin); }
:root[data-theme="dark"] .form-grup select:disabled { background: rgba(255,255,255,.03); color: #6b6585; }
:root[data-theme="dark"] select option { background: #1e1838; color: #ece8fb; }
:root[data-theme="dark"] .form-grup input:focus,
:root[data-theme="dark"] .form-grup select:focus,
:root[data-theme="dark"] .form-grup textarea:focus { box-shadow: 0 0 0 4px rgba(167,139,250,.22); }

:root[data-theme="dark"] .mesaj { background: #231c42; color: #ddd6fe; }
:root[data-theme="dark"] .yuzen-kart { background: #231c42; color: var(--metin); }

:root[data-theme="dark"] .ik1 { background: rgba(139,92,246,.22); }
:root[data-theme="dark"] .ik2 { background: rgba(245,158,11,.20); }
:root[data-theme="dark"] .ik3 { background: rgba(34,197,94,.18); }
:root[data-theme="dark"] .ik4 { background: rgba(59,130,246,.20); }

:root[data-theme="dark"] .bildirim { background: rgba(236,72,153,.08); border-color: #5b2a49; }
:root[data-theme="dark"] .bildirim.yeni { background: rgba(236,72,153,.16); border-color: #be185d; }

:root[data-theme="dark"] .adim { background: rgba(255,255,255,.06); }
:root[data-theme="dark"] .adim .no { background: #4b4470; }
:root[data-theme="dark"] .adim.aktif { background: rgba(139,92,246,.25); color: #ddd6fe; }
:root[data-theme="dark"] .adim.aktif .no { background: var(--mor-a); }
:root[data-theme="dark"] .adim.tamam { background: rgba(34,197,94,.20); color: #86efac; }
:root[data-theme="dark"] .adim.tamam .no { background: #22c55e; }
:root[data-theme="dark"] .adim-blok { background: rgba(255,255,255,.04); }

:root[data-theme="dark"] .doktor-kart { background: rgba(255,255,255,.05); border-color: #3a3358; }
:root[data-theme="dark"] .doktor-kart.secili { background: rgba(139,92,246,.22); border-color: var(--mor-a); }
:root[data-theme="dark"] .d-saat { background: rgba(20,184,166,.20); color: #5eead4; }

:root[data-theme="dark"] .saat-btn { background: rgba(255,255,255,.05); border-color: #3a3358; color: #ddd6fe; }
:root[data-theme="dark"] .saat-btn:hover:not(:disabled) { background: rgba(236,72,153,.15); }
:root[data-theme="dark"] .saat-btn.secili { background: linear-gradient(135deg, var(--mor-a), var(--pembe)); color: #fff; border-color: transparent; }
:root[data-theme="dark"] .saat-btn.dolu { background: rgba(255,255,255,.03); color: #6b6585; border-color: #2c2646; }

:root[data-theme="dark"] .ozet { background: rgba(139,92,246,.12); border-color: #5b4b8a; }
:root[data-theme="dark"] .bos { background: rgba(255,255,255,.04); }

:root[data-theme="dark"] .sekme { background: rgba(255,255,255,.06); }
:root[data-theme="dark"] .sekme.aktif { background: linear-gradient(135deg, var(--mor-a), var(--pembe)); color: #fff; }
:root[data-theme="dark"] .randevu { background: rgba(255,255,255,.05); }
:root[data-theme="dark"] .randevu:hover { box-shadow: 0 14px 30px rgba(0,0,0,.4); }
:root[data-theme="dark"] .tarih-blok { background: linear-gradient(160deg, rgba(139,92,246,.28), rgba(236,72,153,.22)); }
:root[data-theme="dark"] .randevu-no { color: #7f789c; }

:root[data-theme="dark"] .durum { background: rgba(245,158,11,.20); color: #fcd34d; }
:root[data-theme="dark"] .durum.Onaylandi { background: rgba(34,197,94,.20); color: #86efac; }
:root[data-theme="dark"] .durum.Tamamlandi { background: rgba(59,130,246,.22); color: #93c5fd; }
:root[data-theme="dark"] .durum.Iptal { background: rgba(244,63,94,.20); color: #fda4af; }

:root[data-theme="dark"] .sifirla-kutu,
:root[data-theme="dark"] .oneri-kutu { background: rgba(139,92,246,.12); border-color: #5b4b8a; }
:root[data-theme="dark"] .hizli-erisim { background: rgba(255,255,255,.04); }
:root[data-theme="dark"] .chip { background: rgba(255,255,255,.05); border-color: #3a3358; }
:root[data-theme="dark"] .oneri-chip { border-color: #5b4b8a; }
:root[data-theme="dark"] .oneri-satir input { background: rgba(255,255,255,.06); border-color: #3a3358; color: var(--metin); }
:root[data-theme="dark"] .fav-btn { background: rgba(255,255,255,.08); color: #8a84a8; }
:root[data-theme="dark"] .fav-btn.acik { background: rgba(245,158,11,.22); color: #fbbf24; }
:root[data-theme="dark"] .oneri-acil { background: rgba(244,63,94,.18); color: #fecdd3; border-color: #be123c; }

/* Hareketi azaltmak isteyenler için (kartlar) */
@media (prefers-reduced-motion: reduce) {
    .tanitim, .tanitim::before, .tanitim::after, .hos, .hos::after, .yuzen-kart { animation: none; }
}

@media (max-width: 900px) {
    .giris-grid, .karsilama { grid-template-columns: 1fr; }
    .tanitim { min-height: auto; }
    .yuzen-kart { display: none; }
    .grid-4 { grid-template-columns: repeat(2, minmax(0,1fr)); }
    .konum-grid { grid-template-columns: repeat(2, minmax(0,1fr)); }
    .randevu-liste { grid-template-columns: 1fr; }
}
@media (max-width: 560px) {
    .saat-grid { grid-template-columns: repeat(4, minmax(0,1fr)); }
    .konum-grid { grid-template-columns: 1fr; }
    .kart { padding: 18px; }
    .topbar { padding: 10px 14px; }
    .ust-yazi.selam { display: none; }
    .randevu { flex-direction: column; }
    .tarih-blok { width: 100%; display: flex; gap: 12px; justify-content: center; align-items: baseline; }
    .tarih-blok .saat { margin-top: 0; }
    .blob { filter: blur(50px); }
}
</style>
</head>

<body class="<?= $girisli ? 'panel' : 'giris' ?>">
<div class="arka-plan">
    <span class="blob b1"></span><span class="blob b2"></span><span class="blob b3"></span>
    <span class="blob b4"></span><span class="blob b5"></span>
    <div class="yuzen-arti">
        <span style="--x:4%;  --s:26px; --d:12s; --g:0s">✚</span>
        <span style="--x:11%; --s:18px; --d:15s; --g:-4s">✚</span>
        <span style="--x:18%; --s:34px; --d:14s; --g:-8s">✚</span>
        <span style="--x:25%; --s:20px; --d:11s; --g:-2s">✚</span>
        <span style="--x:32%; --s:28px; --d:16s; --g:-10s">✚</span>
        <span style="--x:39%; --s:16px; --d:13s; --g:-6s">✚</span>
        <span style="--x:46%; --s:30px; --d:15s; --g:-1s">✚</span>
        <span style="--x:53%; --s:22px; --d:12s; --g:-11s">✚</span>
        <span style="--x:60%; --s:32px; --d:17s; --g:-7s">✚</span>
        <span style="--x:67%; --s:18px; --d:11s; --g:-3s">✚</span>
        <span style="--x:74%; --s:26px; --d:14s; --g:-9s">✚</span>
        <span style="--x:81%; --s:20px; --d:16s; --g:-12s">✚</span>
        <span style="--x:88%; --s:30px; --d:13s; --g:-5s">✚</span>
        <span style="--x:95%; --s:24px; --d:15s; --g:-13s">✚</span>
    </div>
</div>

<div class="container">

<header class="topbar">
    <a class="logo" href="index.php"><span class="ikon">✚</span><span>Randevu<span class="pl">Plus</span></span></a>

    <?php if ($girisli): ?>
        <div class="menu">
            <button type="button" class="ikincil tema-btn" id="temaBtn" aria-label="Temayı değiştir"></button>
            <span class="avatar"><?= h(mb_strtoupper(mb_substr($ad, 0, 1))) ?></span>
            <span class="ust-yazi selam">Merhaba, <strong><?= h($ad) ?></strong></span>
            <a class="buton ikincil" href="index.php?cikis=1">Çıkış Yap</a>
        </div>
    <?php else: ?>
        <div class="menu">
            <button type="button" class="ikincil tema-btn" id="temaBtn" aria-label="Temayı değiştir"></button>
            <a class="buton ikincil" href="index.php">Giriş Yap</a>
            <a class="buton birincil" href="index.php?sayfa=kayit">Hesap Oluştur</a>
        </div>
    <?php endif; ?>
</header>

<?php if ($mesaj !== ''): ?>
    <div class="mesaj" id="toast"><span><?= h($mesaj) ?></span><button type="button" onclick="this.parentNode.remove()" aria-label="Kapat">✕</button></div>
<?php endif; ?>

<?php if (!$girisli): ?>

    <!-- GIRIS VE KAYIT EKRANI -->
    <main class="giris-grid">
        <section class="tanitim">
            <span class="rozet">✦ Türkiye'nin 81 ili</span>
            <h1>Doktor randevunu<br>dakikalar içinde al.</h1>
            <p>
                Türkiye'deki tüm illerin ve ilçelerin hastaneleri tek yerde. İl, ilçe, hastane ve polikliniğini seç;
                doktorunu ve sana uygun saati belirle.
            </p>
            <div class="ist-satir">
                <div class="ist-kutu"><b><?= (int)$genelIst['il'] ?></b><span>İl</span></div>
                <div class="ist-kutu"><b><?= number_format((int)$genelIst['ilce'], 0, ',', '.') ?></b><span>İlçe</span></div>
                <div class="ist-kutu"><b><?= number_format((int)$genelIst['hastane'], 0, ',', '.') ?></b><span>Hastane</span></div>
                <div class="ist-kutu"><b><?= number_format((int)$genelIst['doktor'], 0, ',', '.') ?></b><span>Doktor</span></div>
                <div class="ist-kutu"><b><?= (int)$genelIst['poliklinik'] ?></b><span>Poliklinik</span></div>
            </div>
            <div class="ozellik">✦ Her hastanede kendi polikliniklerine göre doktor listesi</div>
            <div class="ozellik">✦ 30 dakikalık dolu / boş saat seçimi</div>
            <div class="ozellik">✦ Otomatik onay ve anlık bildirimler</div>

            <div class="yuzen-kart">
                <small>Örnek randevu</small>
                <b>Uzm. Dr. Zeynep Kaya</b>
                <small>Kardiyoloji · 10:30</small>
                <div class="ok">✔ Onaylandı</div>
            </div>
        </section>

        <section class="kart form-kart">
            <?php if ($sayfa === 'kayit'): ?>
                <h2>Hesap Oluştur</h2>
                <p class="alt-baslik">Bilgilerini girerek yeni bir hasta hesabı oluştur.</p>

                <form method="post" action="index.php">
                    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                    <input type="hidden" name="islem" value="kayit">

                    <div class="form-grup">
                        <label>Ad Soyad</label>
                        <input type="text" name="ad_soyad" required maxlength="100" placeholder="Adın ve soyadın">
                    </div>
                    <div class="form-grup">
                        <label>E-posta</label>
                        <input type="email" name="email" required maxlength="150" placeholder="ornek@mail.com">
                    </div>
                    <div class="form-grup">
                        <label>Parola</label>
                        <input type="password" name="parola" required minlength="6" placeholder="En az 6 karakter">
                    </div>

                    <button class="birincil tam" type="submit">Hesap Oluştur</button>
                </form>

                <p class="alt-baslik" style="text-align:center;margin-top:20px">
                    Zaten hesabın var mı? <a href="index.php">Giriş yap</a>
                </p>

            <?php elseif ($sayfa === 'sifremi_unuttum'): ?>
                <h2>Parolanı mı unuttun? 🔑</h2>
                <p class="alt-baslik">Hesabının e-posta adresini yaz, parolanı sıfırlaman için bir bağlantı hazırlayalım. Bağlantı 30 dakika geçerlidir.</p>

                <?php if ($sifirlaLink !== ''): ?>
                    <div class="sifirla-kutu">
                        <b>🧪 Demo modu</b>
                        <p>E-posta gönderimi kapalı olduğu için sıfırlama bağlantın burada gösteriliyor:</p>
                        <a class="buton birincil tam" href="<?= h($sifirlaLink) ?>">Parolamı sıfırla →</a>
                    </div>
                <?php endif; ?>

                <form method="post" action="index.php">
                    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                    <input type="hidden" name="islem" value="sifirla_iste">
                    <div class="form-grup">
                        <label>E-posta Adresi</label>
                        <input type="email" name="email" required maxlength="150" placeholder="Kayıtlı e-posta adresin">
                    </div>
                    <button class="birincil tam" type="submit">Bağlantı Gönder</button>
                </form>

                <p class="alt-baslik" style="text-align:center;margin-top:20px">
                    <a href="index.php">← Girişe dön</a>
                </p>

            <?php elseif ($sayfa === 'sifirla'): ?>
                <?php if ($sifirlaGecerli): ?>
                    <h2>Yeni parolanı belirle 🔒</h2>
                    <p class="alt-baslik">En az 6 karakterlik yeni bir parola yaz.</p>
                    <form method="post" action="index.php">
                        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                        <input type="hidden" name="islem" value="sifirla_uygula">
                        <input type="hidden" name="token" value="<?= h($sifirlaToken) ?>">
                        <div class="form-grup">
                            <label>Yeni Parola</label>
                            <input type="password" name="parola" required minlength="6" placeholder="En az 6 karakter">
                        </div>
                        <div class="form-grup">
                            <label>Yeni Parola (tekrar)</label>
                            <input type="password" name="parola2" required minlength="6" placeholder="Parolanı tekrar yaz">
                        </div>
                        <button class="birincil tam" type="submit">Parolayı Güncelle</button>
                    </form>
                <?php else: ?>
                    <h2>Bağlantı geçersiz ⚠️</h2>
                    <p class="alt-baslik">Bu parola sıfırlama bağlantısı geçersiz, süresi dolmuş veya daha önce kullanılmış.</p>
                    <a class="buton birincil tam" href="index.php?sayfa=sifremi_unuttum">Yeni bağlantı iste</a>
                <?php endif; ?>
                <p class="alt-baslik" style="text-align:center;margin-top:20px">
                    <a href="index.php">← Girişe dön</a>
                </p>

            <?php else: ?>
                <h2>Tekrar Hoş Geldin! 👋</h2>
                <p class="alt-baslik">Devam etmek için hesabına giriş yap.</p>

                <form method="post" action="index.php" id="girisFormu">
                    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                    <input type="hidden" name="islem" value="giris">

                    <div class="form-grup">
                        <label>E-posta Adresi</label>
                        <input type="email" name="email" required placeholder="E-posta adresin">
                    </div>
                    <div class="form-grup">
                        <label>Parola</label>
                        <input type="password" name="parola" required placeholder="Parolan">
                        <a class="unuttum" href="index.php?sayfa=sifremi_unuttum">Parolamı unuttum</a>
                    </div>

                    <button class="birincil tam" type="submit">Giriş Yap →</button>
                </form>

                <p class="alt-baslik" style="text-align:center;margin-top:20px">
                    Henüz hesabın yok mu? <a href="index.php?sayfa=kayit">Hemen kayıt ol</a>
                </p>
            <?php endif; ?>
        </section>
    </main>

<?php elseif ($rol === 'hasta'): ?>

    <!-- HASTA PANELI -->
    <main class="icerik">

        <div class="karsilama">
            <div class="hos">
                <h1><?= h($selam) ?>, <?= h(explode(' ', trim($ad))[0]) ?> 🌸</h1>
                <p>Randevularını oluşturabilir, durumlarını takip edebilir ve gerektiğinde iptal edebilirsin.</p>
                <a class="buton" href="#randevuAlani">＋ Yeni Randevu Al</a>
            </div>
            <div class="sonraki">
                <div class="etiket-ust">Yaklaşan randevun</div>
                <?php if ($sonraki): ?>
                    <h3><?= h($sonraki['unvan'] ?: 'Dr.') ?> <?= h($sonraki['doktor_adi']) ?></h3>
                    <div class="detay"><?= h($sonraki['poliklinik']) ?> · <?= h($sonraki['hastane']) ?></div>
                    <div class="zaman">📅 <?= h(date('d.m.Y', strtotime($sonraki['randevu_tarihi']))) ?> · <?= h(date('H:i', strtotime($sonraki['randevu_tarihi']))) ?></div>
                    <div class="detay"><?= h(gunAdi($sonraki['randevu_tarihi'])) ?></div>
                <?php else: ?>
                    <h3>Yaklaşan randevun yok</h3>
                    <div class="detay">Soldaki butonla hemen yeni bir randevu oluşturabilirsin.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid-4">
            <div class="istatistik"><div class="ikon-kutu ik1">📋</div><div><div class="sayi"><?= count($randevular) ?></div><div class="etiket">Toplam Randevu</div></div></div>
            <div class="istatistik"><div class="ikon-kutu ik2">⏳</div><div><div class="sayi"><?= (int)$sayac['Bekliyor'] ?></div><div class="etiket">Bekleyen</div></div></div>
            <div class="istatistik"><div class="ikon-kutu ik3">✅</div><div><div class="sayi"><?= (int)$sayac['Onaylandi'] ?></div><div class="etiket">Onaylanan</div></div></div>
            <div class="istatistik"><div class="ikon-kutu ik4">🏁</div><div><div class="sayi"><?= (int)$sayac['Tamamlandi'] ?></div><div class="etiket">Tamamlanan</div></div></div>
        </div>

        <details class="kart bildirim-kart" <?= $okunmamisBildirim > 0 ? 'open' : '' ?>>
            <summary>🔔 Bildirimler <span class="durum"><?= $okunmamisBildirim ?> yeni</span></summary>
            <div style="margin-top:14px">
            <?php if (!$bildirimler): ?>
                <p class="alt-baslik">Henüz bildirimin yok.</p>
            <?php else: foreach ($bildirimler as $b): ?>
                <div class="bildirim <?= !$b['okundu'] ? 'yeni' : '' ?>">
                    <?= h($b['mesaj']) ?> <span class="ust-yazi">· <?= h(tarihAdi($b['olusturma_tarihi'])) ?></span>
                </div>
            <?php endforeach; endif; ?>
            <?php if ($okunmamisBildirim > 0): ?>
                <form method="post" style="margin-top:10px">
                    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                    <input type="hidden" name="islem" value="bildirimleri_oku">
                    <button class="ikincil" type="submit">Tümünü okundu işaretle</button>
                </form>
            <?php endif; ?>
            </div>
        </details>

        <!-- YENI RANDEVU SIHIRBAZI -->
        <section class="kart" id="randevuAlani">
            <h2>＋ Yeni Randevu Al</h2>
            <p class="alt-baslik">Türkiye'nin herhangi bir ilindeki hastaneyi seç, polikliniğe ve doktora göre uygun saate dokun.</p>

            <div class="adimlar" id="adimlar">
                <div class="adim aktif"><span class="no">1</span> Poliklinik</div>
                <div class="adim"><span class="no">2</span> Doktor</div>
                <div class="adim"><span class="no">3</span> Tarih &amp; Saat</div>
                <div class="adim"><span class="no">4</span> Onay</div>
            </div>

            <!-- FAVORI DOKTORLAR VE SON ARAMALAR -->
            <div class="hizli-erisim" id="hizliErisim" hidden>
                <div id="favoriBolum" hidden>
                    <div class="hizli-baslik">⭐ Favori doktorların</div>
                    <div id="favoriListe" class="chip-liste"></div>
                </div>
                <div id="aramaBolum" hidden>
                    <div class="hizli-baslik">🕘 Son aramaların <button type="button" class="link-btn" id="aramaTemizle">Temizle</button></div>
                    <div id="aramaListe" class="chip-liste"></div>
                </div>
            </div>

            <form method="post" id="randevuFormu">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <input type="hidden" name="islem" value="randevu_olustur">
                <input type="hidden" name="doktor_id" id="randevuDoktor" value="">
                <input type="hidden" name="randevu_tarihi" id="randevuTarihSaat" value="">

                <div class="adim-blok">
                    <h3><span class="no">1</span> İl, ilçe, hastane ve poliklinik</h3>
                    <div class="konum-grid">
                        <div class="form-grup"><label>İl</label><select id="randevuIl"><option value="">İl seç</option></select></div>
                        <div class="form-grup"><label>İlçe</label><select id="randevuIlce" disabled><option value="">Önce il seç</option></select></div>
                        <div class="form-grup"><label>Hastane</label><select id="randevuHastane" disabled><option value="">Önce ilçe seç</option></select></div>
                        <div class="form-grup"><label>Poliklinik</label><select id="randevuPoliklinik" disabled><option value="">Önce hastane seç</option></select></div>
                    </div>
                    <div class="ust-yazi konum-bilgi" id="konumBilgi"></div>
                </div>

                <div class="adim-blok">
                    <h3><span class="no">2</span> Doktorunu seç</h3>
                    <div class="arama"><input type="search" id="doktorAra" placeholder="🔍 Doktor adı ara..." disabled></div>
                    <div id="doktorListesi" class="doktor-grid"><div class="bos">Önce poliklinik seç, doktorlar burada listelenecek.</div></div>
                </div>

                <div class="adim-blok">
                    <h3><span class="no">3</span> Tarih ve saat</h3>
                    <p class="ust-yazi" style="margin-top:0">Hafta sonları kapalıdır, 12.00–13.00 öğle arasıdır. En fazla 60 gün sonrasına randevu alınabilir.</p>
                    <div class="form-grup" style="max-width:280px;margin-top:0">
                        <input type="date" id="randevuTarihi" min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+60 days')) ?>">
                    </div>
                    <div id="saatMesaj" class="bos">Önce doktor ve tarih seç.</div>
                    <div id="saatGruplari" hidden>
                        <div class="saat-grup-baslik">☀️ ÖĞLEDEN ÖNCE</div>
                        <div id="saatSabah" class="saat-grid"></div>
                        <div class="saat-grup-baslik">🌤️ ÖĞLEDEN SONRA</div>
                        <div id="saatOgle" class="saat-grid"></div>
                    </div>
                </div>

                <div class="adim-blok">
                    <h3><span class="no">4</span> Açıklama ve onay</h3>
                    <div class="form-grup" style="margin-top:0">
                        <label>Randevu Açıklaması (isteğe bağlı)</label>
                        <textarea name="aciklama" maxlength="500" placeholder="Şikâyetini veya randevu nedenini kısaca yazabilirsin..."></textarea>
                    </div>
                    <div class="ozet" id="randevuOzeti" hidden></div>
                    <button class="birincil tam" type="submit" id="randevuGonder" style="margin-top:14px" disabled>Randevuyu Onayla</button>
                    <p class="ust-yazi" id="randevuKuralMesaji" style="margin-bottom:0"></p>
                </div>
            </form>
        </section>

        <!-- RANDEVULARIM -->
        <section class="kart">
            <h2>Randevularım</h2>
            <p class="alt-baslik">Oluşturduğun tüm randevular burada listelenir.</p>

            <?php if (!$randevular): ?>
                <div class="bos">Henüz randevun yok. İlk randevunu yukarıdan oluşturabilirsin. ♡</div>
            <?php else: ?>
                <div class="sekmeler">
                    <button type="button" class="sekme aktif" data-filtre="hepsi">Tümü</button>
                    <button type="button" class="sekme" data-filtre="yaklasan">Yaklaşan</button>
                    <button type="button" class="sekme" data-filtre="gecmis">Geçmiş</button>
                    <button type="button" class="sekme" data-filtre="iptal">İptal</button>
                </div>

                <div class="randevu-liste" id="randevuListe">
                    <?php foreach ($randevular as $r): [$g, $ay] = gunAy($r['randevu_tarihi']); ?>
                        <div class="randevu" data-grup="<?= h($r['grup']) ?>">
                            <div class="tarih-blok">
                                <div class="gun"><?= h($g) ?></div>
                                <div class="ay"><?= h($ay) ?></div>
                                <div class="saat"><?= h(date('H:i', strtotime($r['randevu_tarihi']))) ?></div>
                            </div>
                            <div class="randevu-govde">
                                <div class="randevu-baslik">
                                    <h3><?= h($r['unvan'] ?: 'Dr.') ?> <?= h($r['doktor_adi']) ?></h3>
                                    <span class="durum <?= h($r['durum']) ?>"><?= h(durumAdi($r['durum'])) ?></span>
                                </div>
                                <div class="detay">
                                    🩺 <?= h($r['poliklinik'] ?: ($r['uzmanlik'] ?: 'Genel')) ?><br>
                                    🏥 <?= h($r['hastane'] ?? '') ?><br>
                                    📍 <?= h($r['il'] ?? '') ?> / <?= h($r['ilce'] ?? '') ?> · <?= h(gunAdi($r['randevu_tarihi'])) ?>
                                </div>
                                <?php if (!empty($r['aciklama'])): ?>
                                    <div class="detay">💬 <?= h($r['aciklama']) ?></div>
                                <?php endif; ?>
                                <div class="randevu-no">RANDEVU NO: RND-<?= str_pad((string)(int)$r['id'], 6, '0', STR_PAD_LEFT) ?></div>

                                <?php if (in_array($r['durum'], ['Bekliyor', 'Onaylandi'], true)): ?>
                                    <form method="post" class="islem-formu"
                                          onsubmit="return confirm('Bu randevuyu iptal etmek istiyor musun?')">
                                        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                                        <input type="hidden" name="islem" value="randevu_iptal">
                                        <input type="hidden" name="randevu_id" value="<?= (int)$r['id'] ?>">
                                        <button type="submit" class="sil">Randevuyu İptal Et</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="bos" id="filtreBos" hidden>Bu filtrede randevu bulunamadı.</div>
            <?php endif; ?>
        </section>
    </main>

<?php else: ?>
    <main class="icerik"><section class="kart"><h2>Bu hesap türü kullanılamıyor.</h2><a class="buton birincil" href="index.php?cikis=1">Çıkış Yap</a></section></main>
<?php endif; ?>

<footer class="alt">
    RandevuPlus © <?= date('Y') ?> · Randevularını kolayca yönet ♡<br>
    <small>Bu projedeki hastane, poliklinik ve doktor bilgileri demo amaçlı kurgusal verilerdir.</small>
</footer>

</div>

<?php if (!$girisli): ?>
<!-- GIRIS ANIMASYONU -->
<div class="giris-yukleme" id="girisYukleme" aria-live="polite">
    <div class="gy-kutu">
        <svg class="gy-kalp" viewBox="0 0 100 90" aria-hidden="true">
            <defs>
                <linearGradient id="gyGrad" x1="0" y1="1" x2="0" y2="0">
                    <stop offset="0" stop-color="#b91c1c"/>
                    <stop offset="1" stop-color="#ef4444"/>
                </linearGradient>
                <clipPath id="gyKes">
                    <path d="M50 85 C20 62 4 46 4 28 C4 14 15 5 27 5 C37 5 46 11 50 20 C54 11 63 5 73 5 C85 5 96 14 96 28 C96 46 80 62 50 85 Z"/>
                </clipPath>
            </defs>
            <path d="M50 85 C20 62 4 46 4 28 C4 14 15 5 27 5 C37 5 46 11 50 20 C54 11 63 5 73 5 C85 5 96 14 96 28 C96 46 80 62 50 85 Z" fill="rgba(239,68,68,.14)"/>
            <g clip-path="url(#gyKes)">
                <rect id="gyDolgu" x="0" y="90" width="100" height="90" fill="url(#gyGrad)"/>
            </g>
            <path d="M50 85 C20 62 4 46 4 28 C4 14 15 5 27 5 C37 5 46 11 50 20 C54 11 63 5 73 5 C85 5 96 14 96 28 C96 46 80 62 50 85 Z" fill="none" stroke="#dc2626" stroke-width="3" stroke-linejoin="round"/>
        </svg>
        <svg class="gy-ekg" viewBox="0 0 420 80" fill="none" aria-hidden="true">
            <defs>
                <linearGradient id="gyEkgGrad" x1="0" y1="0" x2="1" y2="0">
                    <stop offset="0" stop-color="#f87171"/>
                    <stop offset=".5" stop-color="#ef4444"/>
                    <stop offset="1" stop-color="#dc2626"/>
                </linearGradient>
            </defs>
            <path id="gyCizgi" pathLength="1" stroke="url(#gyEkgGrad)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"
                  stroke-dasharray="1" stroke-dashoffset="1"
                  d="M0 40H100L112 40L124 28L136 40H158L170 47L184 6L200 74L212 40H252L264 31L280 49L290 40H420"/>
        </svg>
        <div class="gy-yuzde" id="gyYuzde">%0</div>
        <div class="gy-metin" id="gyMetin">Giriş yapılıyor...</div>
    </div>
</div>
<script>
// Giriş: kalp yavaşça dolar, EKG çizgisi çizilir, sonunda ana sayfaya girilir.
(function () {
    const form = document.getElementById('girisFormu');
    const kaplama = document.getElementById('girisYukleme');
    if (!form || !kaplama) return;
    const dolgu = document.getElementById('gyDolgu');
    const cizgi = document.getElementById('gyCizgi');
    const yuzde = document.getElementById('gyYuzde');
    const metin = document.getElementById('gyMetin');
    const azalt = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
    const SURE = azalt ? 1200 : 3400;   // milisaniye
    let calisiyor = false;

    function sifirla() {
        calisiyor = false;
        kaplama.classList.remove('acik');
        dolgu.setAttribute('y', '90');
        cizgi.style.strokeDashoffset = '1';
        yuzde.textContent = '%0';
        metin.textContent = 'Giriş yapılıyor...';
    }

    form.addEventListener('submit', e => {
        if (calisiyor) return;
        e.preventDefault();
        calisiyor = true;
        kaplama.classList.add('acik');
        const baslangic = performance.now();

        const adim = t => {
            const lin = Math.min(1, (t - baslangic) / SURE);
            const p = lin < .5 ? 2 * lin * lin : 1 - Math.pow(-2 * lin + 2, 2) / 2;   // yavaş başla, yavaş bitir
            dolgu.setAttribute('y', (90 * (1 - p)).toFixed(2));
            cizgi.style.strokeDashoffset = (1 - p).toFixed(4);
            yuzde.textContent = '%' + Math.round(p * 100);
            if (p > .85)      metin.textContent = 'Hoş geldin!';
            else if (p > .45) metin.textContent = 'Randevuların hazırlanıyor...';
            if (lin < 1) requestAnimationFrame(adim);
            else setTimeout(() => form.submit(), 250);
        };
        requestAnimationFrame(adim);
    });

    // Geri tuşuyla dönülürse animasyon ekranı sıfırlansın.
    window.addEventListener('pageshow', ev => { if (ev.persisted) sifirla(); });
})();
</script>
<?php endif; ?>

<script>
// Bildirim kutusunu birkaç saniye sonra otomatik kapat.
(function () {
    const t = document.getElementById('toast');
    if (t) setTimeout(() => { t.style.transition = 'opacity .4s'; t.style.opacity = '0'; setTimeout(() => t.remove(), 450); }, 6000);
})();
</script>

<script>
// Açık / koyu tema dugmesi
(function () {
    const btn = document.getElementById('temaBtn');
    if (!btn) return;
    const kok = document.documentElement;
    function ikon() {
        const koyu = kok.getAttribute('data-theme') === 'dark';
        btn.textContent = koyu ? '☀️' : '🌙';
        btn.title = koyu ? 'Açık temaya geç' : 'Koyu temaya geç';
    }
    ikon();
    btn.addEventListener('click', () => {
        const yeni = kok.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        kok.classList.add('tema-gecis');
        kok.setAttribute('data-theme', yeni);
        try { localStorage.setItem('tema', yeni); } catch (e) { /* kaydedilemedi */ }
        ikon();
        setTimeout(() => kok.classList.remove('tema-gecis'), 500);
    });
})();
</script>

<?php if ($girisli && $rol === 'hasta'): ?>
<script>
// Randevularım filtre sekmeleri
(function () {
    const sekmeler = document.querySelectorAll('.sekme');
    const kartlar = document.querySelectorAll('#randevuListe .randevu');
    const bos = document.getElementById('filtreBos');
    if (!sekmeler.length) return;
    sekmeler.forEach(s => s.addEventListener('click', () => {
        sekmeler.forEach(x => x.classList.remove('aktif'));
        s.classList.add('aktif');
        let say = 0;
        kartlar.forEach(k => {
            const goster = s.dataset.filtre === 'hepsi' || k.dataset.grup === s.dataset.filtre;
            k.style.display = goster ? '' : 'none';
            if (goster) say++;
        });
        if (bos) bos.hidden = say !== 0;
    }));
})();

// Randevu sihirbazı: il > ilçe > hastane > poliklinik > doktor > tarih/saat (veriler API'den gelir)
(function () {
    const $ = id => document.getElementById(id);
    const iller = <?= json_encode($illerListe, $jsFlags) ?>;
    const benim = <?= json_encode($benimSaatler, $jsFlags) ?>;

    const il = $('randevuIl'), ilce = $('randevuIlce'), hastane = $('randevuHastane'), poliklinik = $('randevuPoliklinik');
    const doktorHidden = $('randevuDoktor'), liste = $('doktorListesi'), ara = $('doktorAra'), konumBilgi = $('konumBilgi');
    const tarih = $('randevuTarihi'), hiddenTarih = $('randevuTarihSaat');
    const sabah = $('saatSabah'), ogle = $('saatOgle'), gruplar = $('saatGruplari'), saatMesaj = $('saatMesaj');
    const ozet = $('randevuOzeti'), gonder = $('randevuGonder'), uyari = $('randevuKuralMesaji');
    const adimlar = document.querySelectorAll('#adimlar .adim');

    let secilen = null;        // seçili doktor
    let doktorHavuzu = [];     // seçili poliklinikteki doktorlar
    let dolu = [];             // seçili doktorun dolu saatleri
    let istekNo = 0;           // eski isteklerin sonucunu yok saymak için

    const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    async function getJson(url) {
        const r = await fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
        if (!r.ok) throw new Error('İstek başarısız (' + r.status + ')');
        return r.json();
    }

    function doldur(sel, baslik, ogeler) {
        sel.innerHTML = '';
        sel.add(new Option(baslik, ''));
        ogeler.forEach(o => sel.add(new Option(o.text, o.value)));
        sel.disabled = ogeler.length === 0;
    }
    const metinSec = sel => sel.value ? sel.options[sel.selectedIndex].text : '';

    function adimGuncelle() {
        const durum = [!!poliklinik.value, !!secilen, !!hiddenTarih.value];
        let aktifVerildi = false;
        adimlar.forEach((el, i) => {
            el.classList.remove('aktif', 'tamam');
            if (i < 3 && durum[i]) el.classList.add('tamam');
            else if (!aktifVerildi) { el.classList.add('aktif'); aktifVerildi = true; }
        });
    }

    function ozetGuncelle() {
        if (secilen && hiddenTarih.value) {
            const [t, s] = hiddenTarih.value.split('T');
            const [y, m, g] = t.split('-');
            ozet.innerHTML = '<b>Randevu özeti</b><br>👨‍⚕️ ' + esc(secilen.unvan + ' ' + secilen.ad) +
                '<br>🩺 ' + esc(secilen.poliklinik) + '<br>🏥 ' + esc(metinSec(hastane)) +
                '<br>📍 ' + esc(metinSec(il) + ' / ' + metinSec(ilce)) +
                '<br>📅 <b>' + g + '.' + m + '.' + y + ' · ' + s + '</b>';
            ozet.hidden = false;
            gonder.disabled = false;
        } else {
            ozet.hidden = true;
            gonder.disabled = true;
        }
        adimGuncelle();
    }

    function saatMesajiGoster(m) { saatMesaj.textContent = m; saatMesaj.hidden = false; gruplar.hidden = true; }

    function saatleriCiz() {
        sabah.innerHTML = ''; ogle.innerHTML = ''; hiddenTarih.value = ''; uyari.textContent = '';
        if (!secilen) { saatMesajiGoster('Önce doktor seç.'); ozetGuncelle(); return; }
        if (!tarih.value) { saatMesajiGoster('Şimdi bir tarih seç.'); ozetGuncelle(); return; }
        const gun = new Date(tarih.value + 'T12:00:00').getDay();
        if (gun === 0 || gun === 6) { saatMesajiGoster('Hafta sonu randevu alınamaz. Lütfen hafta içi bir gün seç.'); ozetGuncelle(); return; }

        for (let dk = 8 * 60; dk < 17 * 60; dk += 30) {
            if (dk >= 12 * 60 && dk < 13 * 60) continue; // öğle arası
            const saat = String(Math.floor(dk / 60)).padStart(2, '0') + ':' + String(dk % 60).padStart(2, '0');
            if (saat < secilen.baslangic || saat >= secilen.bitis) continue;
            const key = tarih.value + ' ' + saat;
            const doktorDolu = dolu.includes(key);
            const benimDolu = benim.includes(key);
            const gecmis = new Date(key.replace(' ', 'T') + ':00') <= new Date();
            const kapali = doktorDolu || benimDolu || gecmis;

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'saat-btn' + (kapali ? ' dolu' : '');
            btn.textContent = saat;
            btn.disabled = kapali;
            if (benimDolu) btn.title = 'Bu saatte başka bir randevun var';
            btn.addEventListener('click', () => {
                document.querySelectorAll('.saat-btn').forEach(b => b.classList.remove('secili'));
                btn.classList.add('secili');
                hiddenTarih.value = tarih.value + 'T' + saat;
                uyari.textContent = '';
                ozetGuncelle();
            });
            (dk < 12 * 60 ? sabah : ogle).appendChild(btn);
        }
        if (!sabah.children.length && !ogle.children.length) {
            saatMesajiGoster('Bu doktor için seçilen tarihte uygun saat yok.');
        } else {
            saatMesaj.hidden = true; gruplar.hidden = false;
        }
        ozetGuncelle();
    }

    function doktorCiz() {
        const q = ara.value.trim().toLocaleLowerCase('tr');
        const uygun = doktorHavuzu.filter(d => !q || (d.unvan + ' ' + d.ad).toLocaleLowerCase('tr').includes(q));
        liste.innerHTML = '';
        if (!uygun.length) { liste.innerHTML = '<div class="bos">Bu seçime uygun doktor bulunamadı.</div>'; return; }
        uygun.forEach(d => {
            const k = document.createElement('button');
            k.type = 'button';
            k.className = 'doktor-kart' + (secilen && secilen.id === d.id ? ' secili' : '');
            const bas = d.ad.split(' ').map(p => p[0]).slice(0, 2).join('');
            k.innerHTML = '<div class="d-avatar">' + esc(bas) + '</div><div><div class="d-ad">' + esc(d.unvan + ' ' + d.ad) +
                '</div><div class="d-alt">' + esc(d.poliklinik) + '</div><span class="d-saat">🕒 ' + esc(d.baslangic + '–' + d.bitis) + '</span></div>';
            k.addEventListener('click', async () => {
                secilen = d; doktorHidden.value = String(d.id);
                doktorCiz();
                const no = ++istekNo;
                saatMesajiGoster('Uygun saatler yükleniyor...');
                try { dolu = await getJson('index.php?api=dolu&doktor=' + d.id); } catch (e) { dolu = []; }
                if (no !== istekNo) return;
                saatleriCiz();
            });
            liste.appendChild(k);
        });
    }

    function doktorSifirla(mesaj) {
        istekNo++;
        secilen = null; doktorHidden.value = ''; doktorHavuzu = []; dolu = [];
        ara.value = ''; ara.disabled = true;
        liste.innerHTML = '<div class="bos">' + esc(mesaj) + '</div>';
        saatleriCiz();
    }
    const ILK_MESAJ = 'Önce poliklinik seç, doktorlar burada listelenecek.';

    doldur(il, 'İl seç', iller.map(x => ({ value: String(x.id), text: x.ad })));

    il.addEventListener('change', async () => {
        doldur(hastane, 'Önce ilçe seç', []); doldur(poliklinik, 'Önce hastane seç', []);
        konumBilgi.textContent = '';
        doktorSifirla(ILK_MESAJ);
        if (!il.value) { doldur(ilce, 'Önce il seç', []); return; }
        doldur(ilce, 'İlçeler yükleniyor...', []);
        const no = ++istekNo;
        try {
            const l = await getJson('index.php?api=ilceler&il=' + encodeURIComponent(il.value));
            if (no !== istekNo) return;
            doldur(ilce, 'İlçe seç (' + l.length + ')', l.map(x => ({ value: String(x.id), text: x.ad })));
        } catch (e) { doldur(ilce, 'İlçeler yüklenemedi', []); }
    });

    ilce.addEventListener('change', async () => {
        doldur(poliklinik, 'Önce hastane seç', []);
        konumBilgi.textContent = '';
        doktorSifirla(ILK_MESAJ);
        if (!ilce.value) { doldur(hastane, 'Önce ilçe seç', []); return; }
        doldur(hastane, 'Hastaneler yükleniyor...', []);
        const no = ++istekNo;
        try {
            const l = await getJson('index.php?api=hastaneler&ilce=' + encodeURIComponent(ilce.value));
            if (no !== istekNo) return;
            doldur(hastane, 'Hastane seç (' + l.length + ')', l.map(x => ({ value: String(x.id), text: x.ad })));
        } catch (e) { doldur(hastane, 'Hastaneler yüklenemedi', []); }
    });

    hastane.addEventListener('change', async () => {
        konumBilgi.textContent = '';
        doktorSifirla(ILK_MESAJ);
        if (!hastane.value) { doldur(poliklinik, 'Önce hastane seç', []); return; }
        doldur(poliklinik, 'Poliklinikler yükleniyor...', []);
        const no = ++istekNo;
        try {
            const l = await getJson('index.php?api=poliklinikler&hastane=' + encodeURIComponent(hastane.value));
            if (no !== istekNo) return;
            doldur(poliklinik, 'Poliklinik seç (' + l.length + ')', l.map(x => ({ value: x, text: x })));
            konumBilgi.textContent = '🏥 ' + metinSec(hastane) + ' hastanesinde ' + l.length + ' poliklinik bulunuyor.';
        } catch (e) { doldur(poliklinik, 'Poliklinikler yüklenemedi', []); }
    });

    poliklinik.addEventListener('change', async () => {
        doktorSifirla(ILK_MESAJ);
        adimGuncelle();
        if (!poliklinik.value) return;
        liste.innerHTML = '<div class="bos">Doktorlar yükleniyor...</div>';
        const no = ++istekNo;
        try {
            const l = await getJson('index.php?api=doktorlar&hastane=' + encodeURIComponent(hastane.value) +
                                    '&poliklinik=' + encodeURIComponent(poliklinik.value));
            if (no !== istekNo) return;
            if (!Array.isArray(l)) throw new Error('Geçersiz yanıt');
            doktorHavuzu = l.map(d => ({ ...d, id: Number(d.id), unvan: d.unvan || 'Dr.' }));
            ara.disabled = false;
            doktorCiz();
        } catch (e) { liste.innerHTML = '<div class="bos">Doktorlar yüklenemedi. Sayfayı yenileyip tekrar dene.</div>'; }
        adimGuncelle();
    });

    ara.addEventListener('input', doktorCiz);
    tarih.addEventListener('change', saatleriCiz);

    $('randevuFormu').addEventListener('submit', e => {
        if (!doktorHidden.value || !hiddenTarih.value) {
            e.preventDefault();
            uyari.textContent = 'Lütfen doktor, tarih ve uygun bir saat seç.';
        }
    });

    adimGuncelle();
})();
</script>
<?php endif; ?>
</body>
</html>
