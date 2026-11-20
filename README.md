# Ilmek - Inventory and Sales Management for Haberdashery Shops

Ilmek is a self-hosted web app that helps small shops manage products, stock, and sales from one interface. The UI works on phones and tablets. The application interface is currently in Turkish.

> **Privacy:** This repository does not include a store database, backups, product photos, supplier receipts, or a real store address. The app creates its database when you run it.

## Screenshots

### Sign-in

![Ilmek sign-in screen](screenshots/sign-in.png)

### Staff dashboard

![Ilmek staff dashboard](screenshots/staff-dashboard.png)

### Admin dashboard

![Ilmek admin dashboard](screenshots/admin-dashboard.png)

All screenshots use fictional demo data.

### Themes

28 color themes can be chosen per device under Settings: 17 light, 2 dark, and 9 high-contrast themes (5 on white, 4 on black, each in a different accent color). Every theme is checked at runtime so text stays readable: normal text meets WCAG AA contrast (4.5:1). The high-contrast themes use pure black or white text, full-strength outlines and reach 7:1.

| Kömür (dark) | Şeftali (warm) | Yüksek kontrast, mavi | Yüksek kontrast, sarı |
|---|---|---|---|
| ![Dark theme](screenshots/theme-coal.png) | ![Warm theme](screenshots/theme-peach.png) | ![High-contrast theme](screenshots/theme-contrast.png) | ![High-contrast theme on black](screenshots/theme-contrast-yellow.png) |

![Theme picker](screenshots/themes.png)

## Features

- Manage products, categories, purchase costs, sale prices, and stock
- Record cash, card, or bank-transfer sales and update stock automatically
- Separate administrator and cashier accounts with role-based permissions
- Review sales, payment methods, expenses, estimated gross profit, and low-stock reports
- Track product photos, stock purchases, stock adjustments, and activity history
- Calculate prices using the daily USD/TRY exchange rate published by the Central Bank of the Republic of Türkiye (CBRT)
- Use SQLite, local ZIP backups, and optional Google Drive backups
- Large touch targets and a simple, tablet-friendly interface; 28 color themes including dark and high-contrast
- Choose between the Python server or the PHP/MySQL cPanel deployment

Tax calculation and partial payments are not currently supported.

### Python server vs. PHP/MySQL

The PHP/MySQL version is the complete one. The Python server is a lighter option for trying the app locally and covers the core flows only:
products, sales, sale cancellation, stock-in, expenses (adding), staff accounts, exchange rate and ZIP backups.
These screens need the PHP version and show a "not supported in the Python version" message on the Python server:
returns, suppliers, end-of-day cash close, stock counts, shop settings, backup restore, deleting products and editing or deleting expenses.

## Quick start (Windows)

1. Install [Python 3.11 or later](https://www.python.org/downloads/).
2. Download or clone this repository.
3. Double-click `start.bat`, or run `python server.py` from the project directory.
4. Open `http://127.0.0.1:8000` in your browser.
5. Follow the on-screen instructions to create your administrator account.

The Python server uses only the standard library; no `pip install` step is required. The app creates its database and backups in its data directory. Do not commit live store data.

## Run on Linux

With Python 3.11 or later installed:

```sh
python3 server.py
```

The default port is `8000`; set the `PORT` environment variable to use a different port. For access from other devices or over the internet, use an HTTPS reverse proxy. Do not expose `stok.db`, `backups/`, or `images/` through the web server.

## Deployment

- **Docker + Caddy (Linux VPS):** [deploy/README.md](deploy/README.md)
- **PHP + MySQL (cPanel):** [deploy/CPANEL.md](deploy/CPANEL.md)

Both deployment options use the same interface but have different database and setup requirements. Configure real passwords, setup tokens, and Google Drive OAuth credentials only on your server. Never commit `.env` files or private configuration files.

## Data and backups

The Python version uses SQLite. `stok.db`, `backups/`, and `images/` may contain private store data and are excluded by `.gitignore`. Keep a separate, secure copy of your backups. Never add a real database, export, or backup to a public GitHub repository.

Google Drive backup is optional. If you enable it, provide OAuth credentials through environment variables; never place them in source code or commit them to the repository.

## Security notes

- The interface loads nothing from third parties: fonts are served from `fonts/`, there are no external scripts, and scripts are restricted to the app's own origin by a Content-Security-Policy (sent by `server.py` and by `cpanel-php/.htaccess`).
- Sessions are `HttpOnly`, `SameSite=Strict` cookies; signing out ends the session on the server. Login attempts are rate limited and the password hash is computed whether or not the username exists, so response time does not reveal which usernames are valid.
- After deploying an update, browsers re-check `index.html`, `app.js` and `style.css` on every load, so nobody keeps an old copy. If you change those files, also raise the `?v=` number in `index.html`.

## Project structure

```text
.
├── app.js, style.css, index.html    # Web interface (no build step)
├── fonts/                          # DM Sans and Manrope (SIL OFL), served locally
├── server.py                       # Python + SQLite server
├── start.bat                       # Windows launcher
├── passenger_wsgi.py               # Passenger/WSGI entry point
├── cpanel-php/                     # PHP API and MySQL version
├── deploy/                         # Docker, Caddy, and deployment guides
└── screenshots/                    # Project screenshots
```

## License

This project is distributed under the [MIT License](LICENSE).
