# Chatroom (PHP + MySQL + Swoole WebSocket Library)

A simple chatroom with user auth, groups, starred messages, and a Swoole-based WebSocket server.

## Features
- User signup/login, admin flag
- Group chats and membership
- Real-time messaging via Swoole WebSocket
- Starred messages
- Unread counts

## Tech Stack
- PHP 8.2, MySQL/MariaDB
- Swoole WebSocket server (`src/ChatServer.php`)
- Apache (XAMPP) for the HTTP app (`public/`)

## Requirements
- PHP 8.1+ (8.2 recommended)
- MySQL/MariaDB
- XAMPP on Windows and Docker Desktop
- Composer not required

## Setup (Local/XAMPP)
1. Create the database:
   - Import `setup.sql` into MySQL.
2. Configure app:
   - Copy `config.example.php` to `config.php` and adjust DB creds.
3. Start Apache/MySQL in XAMPP.
4. Create an admin user:
   - EITHER run `php create_admin.php` from the project root (CLI only; do not expose to the web),
   - OR use the admin UI under `public/admin/` after login if enabled.
5. Visit the app at `http://localhost/chatroom/public/`.

## WebSocket Server
Docker swoole image
  - Build the image and run with `docker-compose.yml` (exposes port 9501) with command "docker-compose up --build"
  - Ensure the app can reach `ws://localhost:9501`.

## Configuration
- HTTP app uses `config.php` (not committed).
- Docker WebSocket uses `docker_config.php`, which reads:
  - `DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`, and `APP_ENV`.

## Folder Structure
- `public/` HTTP endpoints and assets
- `src/ChatServer.php` WebSocket server
- `setup.sql` schema
- `docker-compose.yml`, `Dockerfile` container setup

## Troubleshooting
- If tokens fail, ensure `ws_tokens` table exists and server time is correct.
- If Docker build fails on missing `docker_config.php`, ensure it's present or use an example file and copy it.

## License
MIT