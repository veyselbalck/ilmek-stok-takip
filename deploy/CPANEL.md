# İlmek: Güzel Hosting cPanel kurulumu (PHP + MySQL)

Güzel Hosting desteği paylaşımlı pakette Python/WSGI çalıştırmadıklarını doğruladı. Bu nedenle mevcut Python uygulaması bu pakette çalışmaz. cPanel için yeni sürüm aynı arayüzü PHP API ve MySQL ile sunar.

## Gerekenler

- PHP 8.1 veya üzeri; `pdo_mysql`, `curl`, `mbstring`, `zlib` eklentileri.
- cPanel MySQL/MariaDB veritabanı ve kullanıcısı.
- Kullanacağınız alt alan adı için SSL (bu yönergede `stok.ornek-alan.com` örnek olarak kullanılır).

PHP sürümünü/eklenti durumunu **Select PHP Version / PHP Extensions** ekranında kontrol edin.

## Kurulum

1. cPanel **MySQL Database Wizard** ile yeni bir veritabanı ve kullanıcı oluşturun; kullanıcıya veritabanı için tüm izinleri verin. cPanel'in eklediği hesap önekli tam adları not edin.
2. cPanel **Domains** ekranında `stok.ornek-alan.com` için belge kökünü bulun. Bu klasörün ana sitenizin belge kökünden ayrı olduğundan emin olun; aynı klasör görünüyorsa dosya yüklemeden önce Güzel Hosting'den subdomain'e ayrı belge kökü tanımlamalarını isteyin. **Application Manager**'daki eski Python uygulamasını **Unregister** edin; bu işlem kayıt/Passenger ayarını kaldırır, uygulama dosyalarını silmez.
3. [PHP cPanel paketini](ilmek-cpanel-php.zip) indirin. İçindekileri subdomain'in belge köküne çıkarın. `.htaccess`, `index.html`, `app.js`, `style.css`, `favicon.svg`, `fonts` klasörü, `api.php`, `image.php`, `lib.php` ve `backup-cli.php` aynı klasörde olmalı (`fonts` klasörü eksikse yazı tipleri yüklenmez). File Manager'da gizli dosyaları göstermeyi açın ki `.htaccess` de yüklensin.
4. Terminal'de `$HOME` değerini görmek için `echo "$HOME"` çalıştırın. Ana dizinde (subdomain belge kökünün dışında) `ilmek-config.php` oluşturup şu örneği kendi bilgilerinizle doldurun:

   ```php
   <?php
   return [
     'db_host' => 'localhost',
     'db_name' => 'CPANEL_ON_EK_ilmek',
     'db_user' => 'CPANEL_ON_EK_kullanici',
     'db_password' => 'MYSQL_KULLANICI_PAROLASI',
     'data_dir' => '/home/CPANEL_KULLANICISI/ilmek-data',
     'setup_token' => 'TEK_KULLANIMLIK_UZUN_RASTGELE_ANAHTAR',
     'google_drive_client_id' => '',
     'google_drive_client_secret' => '',
     'google_drive_refresh_token' => '',
     'google_drive_folder_id' => '',
   ];
   ```

   `setup_token` için Terminal'de `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` çalıştırın. Veritabanı parolasını ve anahtarı bu sohbetle paylaşmayın. Ayar dosyasını `chmod 600 ~/ilmek-config.php` ile koruyun. `data_dir` web kökünün dışında kalmalı.

5. Uygulamayı açın: `https://stok.ornek-alan.com`. İlk istekte veritabanı tabloları otomatik oluşur. Kurulum ekranında `ilmek-config.php` içindeki anahtarı girerek yönetici hesabını oluşturun.
6. Yönetici hesabı kurulunca `ilmek-config.php` içindeki `setup_token` değerini boşaltın ve dosya iznini tekrar `600` yapın.

## Günlük kullanım özellikleri

- Tablet uyumlu arayüzde çalışanlar büyük düğmelerle satış yapar, ürün ekler, stok görür ve gün sonu kasasını kapatır. Yönetici ürün silebilir; satış veya alış geçmişi olan ürünlerde kayıt bütünlüğü için silme engellenir.
- Satış personeli ürün ve başlangıç maliyeti girebilir. Ciro, maliyet, kâr, gider, iade ve yedek yönetici hesabında kalır.
- Yönetici satış iptali, iade, sayım, stok düzeltme, tedarikçi, rapor ve yedek işlemlerini yönetir. Nakit, kart ve IBAN için gün sonu kasası her iki rolde de kullanılabilir.
- Ürün alış/satış bedelleri USD karşılığıyla saklanır; TL etiket fiyatı güncel günlük kurla tam liraya yuvarlanır. Tamamlanmış satış, kendi günündeki TL tutarını ve kuru korur.
- Tema seçimi cihazda saklanır. Tema seçimi ve diğer arayüz tercihleri kullanıcı hesabına değil, kullanılan tarayıcıya özeldir.
- Özellik tabloları mevcut veritabanı korunarak ilk PHP isteğinde oluşturulur. Yükseltmeden önce yönetici panelinden **Yedek indir** ve **Şimdi yedekle** işlemlerini yapın; sonra yeni paketin dosyalarını aynı subdomain belge köküne yükleyip eski dosyaların üzerine yazın. Veritabanını silmeyin. Ardından tarayıcıda Ctrl+F5 ile yenileyin.

## Yedekleme

Uygulama ürün fotoğraflarını ve kayıtları `$data_dir/backups` altında gzip'li JSON olarak yedekler. Günlük çalıştırmak için cPanel **Cron Jobs** bölümünde günde bir kez şu komutu ekleyin. PHP yolunu `command -v php` ile bulun; belge kökü yolunu cPanel **Domains** ekranından alın:

```sh
/usr/local/bin/php /home/CPANEL_KULLANICISI/SUBDOMAIN_BELGE_KOKU/backup-cli.php
```

İsteğe bağlı Google Drive için dört `google_drive_*` değerini ana dizindeki `ilmek-config.php` dosyasına girin. Yerel yedekler Drive ayarlarından bağımsız olarak alınır.

## 502 / hata kaydı

PHP hatalarının ayrıntıları `$data_dir/logs/php-errors.log` içinde tutulur. cPanel **Metrics → Errors** ekranını da kontrol edin. `ilmek-config.php` dosyasını, DB parolasını veya kurulum anahtarını paylaşmayın.
