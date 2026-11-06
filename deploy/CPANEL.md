# cPanel deployment (PHP + MySQL)

This option is for PHP-enabled cPanel hosting. Unlike the Python deployment, it uses MySQL/MariaDB.

## Requirements

- PHP 8.1 or later with the `pdo_mysql`, `curl`, `mbstring`, and `zlib` extensions
- A MySQL/MariaDB database and database user
- A domain with HTTPS enabled

## Setup

1. Create a database and user in cPanel. Grant the user the required permissions for that database.
2. Upload `.htaccess`, `api.php`, `backup-cli.php`, `image.php`, and `lib.php` from `cpanel-php/` to your document root, alongside `index.html`, `app.js`, and `style.css`.
3. Outside the document root, create `ilmek-config.php` with your server's own settings:

   ```php
   <?php
   return [
     'db_host' => 'localhost',
     'db_name' => 'CPANEL_PREFIX_ilmek',
     'db_user' => 'CPANEL_PREFIX_user',
     'db_password' => 'ENTER_YOUR_DATABASE_PASSWORD',
     'data_dir' => '/home/CPANEL_USERNAME/ilmek-data',
     'setup_token' => 'RANDOM_ONE_TIME_SETUP_TOKEN',
     'google_drive_client_id' => '',
     'google_drive_client_secret' => '',
     'google_drive_refresh_token' => '',
     'google_drive_folder_id' => '',
   ];
   ```

   Replace the example values with your server's settings. Restrict access to this file through cPanel/SSH. Do not put it in the document root or commit it to Git.
4. Open your HTTPS site and create the first administrator account using the setup token.
5. After setup, remove the `setup_token` value from the configuration file.

## Backups

The database and product images are stored under `data_dir`. Use cPanel Cron Jobs to run `backup-cli.php` once a day. Keep the backup directory outside the document root and copy backups to another secure location.

Google Drive backups are optional. Enter OAuth credentials only in the server configuration outside the document root. Never add them to source code.
