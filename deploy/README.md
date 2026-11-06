# Deploy with Docker + Caddy (Linux VPS)

## Requirements

- A Linux VPS with Docker Engine and the Docker Compose plugin
- A DNS record pointing your chosen domain to the VPS
- Caddy running on the server and listening on ports 80/443

## Setup

1. Copy the project to the VPS and create `deploy/data` for application data.
2. Copy `deploy/.env.example` to `deploy/.env`.
3. Set `ILMEK_SETUP_TOKEN` in `.env` to a strong, random, one-time token. Enter any real OAuth credentials on the server only.
4. Replace `stok.example.com` in `deploy/Caddyfile.example` with your domain and add the configuration to Caddy.
5. Once DNS and HTTPS are ready, run this command from the project directory:

   ```sh
   docker compose --env-file deploy/.env -f deploy/docker-compose.yml up -d --build
   ```

6. Open the HTTPS address and create the first administrator account. After setup, remove `ILMEK_SETUP_TOKEN` from `.env` and restart the service.

The application binds to `127.0.0.1:8000` inside the container. Route public traffic through the HTTPS reverse proxy. Do not expose the app to the internet before completing setup.

## Protect your data

The SQLite database, photos, and backups live under `deploy/data/`. Do not delete this directory when updating the container. It contains private store data and must not be committed to Git. Update the app by running the same `docker compose` command again.

Google Drive backups are optional. If enabled, provide OAuth credentials in `deploy/.env` on the server only. Never commit `.env`.
