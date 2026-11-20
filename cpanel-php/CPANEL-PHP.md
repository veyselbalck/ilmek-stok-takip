# İlmek: Güzel Hosting cPanel (PHP + MySQL)

Güncel adım adım yönerge için [`deploy/CPANEL.md`](../deploy/CPANEL.md) dosyasına bakın. Bu dosya PHP dağıtımının teknik notlarını içerir.

Bu sürüm Python gerektirmez. Aynı `index.html`, `app.js` ve `style.css` arayüzünü PHP API ve MySQL veritabanıyla kullanır.

## Gerekenler

- PHP 8.1 veya üzeri; `pdo_mysql`, `curl`, `mbstring` ve `zlib` PHP eklentileri.
- cPanel MySQL/MariaDB veritabanı ve kullanıcısı.
- Subdomain için SSL sertifikası.

Güzel Hosting'in yanıtı Python/Passenger desteği olmadığını, PHP uygulamalarının çalıştırılabildiğini doğruluyor. PHP sürümü ve eklentileri **Select PHP Version / PHP Extensions** ekranında kontrol edin.

## Hazırlama

1. cPanel **MySQL Database Wizard** ile yeni bir veritabanı ve kullanıcı oluşturun; kullanıcıya veritabanı için tüm izinleri verin. cPanel'in eklediği hesap önekli tam adları not edin.
2. cPanel **Domains** ekranında `stok.ornek-alan.com` için belge kökünü belirleyin. Python uygulamasını artık bu subdomain'e bağlamayın; Application Manager'daki eski Python uygulamasını **Unregister** edin.
3. `cpanel-php` klasöründeki dosyaları ve ana dizindeki `index.html`, `app.js`, `style.css`, `favicon.svg` dosyalarını ile `fonts` klasörünü subdomain'in belge köküne yükleyin. `index.html`, `.htaccess`, `api.php`, `image.php`, `lib.php` ve `fonts` aynı klasörde olmalı. `.htaccess` API ve ürün görsellerini yönlendirir.
4. Ana dizinde, belge kökünün dışında `/home/CPANEL_KULLANICISI/ilmek-config.php` oluşturun. İçeriğini kendi bilgilerinizle doldurun:

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

   Veritabanı parolası ve kurulum anahtarını dosyaya girin, sohbette paylaşmayın. Kurulum anahtarı için Terminal'de `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` çalıştırabilirsiniz. Dosya iznini `chmod 600 ~/ilmek-config.php` yapın. `data_dir` web kökünün dışında kalmalıdır. Uygulama ilk istekte tabloları kendisi oluşturur.

5. cPanel **SSL/TLS Status** üzerinden subdomain için SSL'in etkin olduğunu doğrulayın. İlk yönetici kurulumu token ile korunur. `https://stok.ornek-alan.com` adresinde ilk yönetici hesabını oluşturun. Sonra `ilmek-config.php` içindeki `setup_token` değerini boşaltın ve dosya iznini tekrar `600` yapın.

## Yedekleme

Uygulama yedekleri `data_dir/backups` altında gzip'li JSON olarak tutar; ürün görselleri de yedeğe eklenir. cPanel **Cron Jobs** bölümünde günde bir kez `backup-cli.php` çalıştırın. Önce Terminal'de `command -v php` ile PHP yolunu bulun; örnek:

```sh
/usr/local/bin/php /home/CPANEL_KULLANICISI/SUBDOMAIN_BELGE_KOKU/backup-cli.php
```

Google Drive yedeği için OAuth değerlerini ana dizindeki `ilmek-config.php` dosyasına ekleyin. Yedek dosyaları sunucu hesabında kalır; Drive yapılandırılırsa ayrıca Drive'a yüklenir.

## Hata kaydı

Uygulama hata ayrıntılarını `data_dir/logs/php-errors.log` içine yazar. cPanel **Metrics → Errors** ekranı da Apache/PHP hatalarını gösterebilir. Yapılandırma dosyasını, veritabanı parolasını ve kurulum anahtarını paylaşmayın.
