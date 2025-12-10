<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php'; // pgsql DSN в db.php

try {
    $pdo->beginTransaction();

    // 0) Універсальна функція-тригер для updated_at
    $pdo->exec("
        CREATE OR REPLACE FUNCTION set_timestamp() RETURNS trigger AS $$
        BEGIN
            NEW.updated_at = NOW();
            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;
    ");

    /* ============================================================
     * 1) КОРИСТУВАЧІ, НАЛАШТУВАННЯ, ЛОГИ, 2FA, RESET-и
     * ============================================================*/

    // 1.1 users
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id              BIGSERIAL PRIMARY KEY,
            username        VARCHAR(255) UNIQUE NOT NULL,
            email           VARCHAR(255) UNIQUE NOT NULL,
            password        VARCHAR(255) NOT NULL,
            reset_token     VARCHAR(255),
            reset_expires   TIMESTAMPTZ,
            created_at      TIMESTAMPTZ DEFAULT NOW()
        );
    ");

    // додаткові поля (ідемпотентно)
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified  BOOLEAN      DEFAULT FALSE;");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS telegram_id     BIGINT;");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS tg_user_id      BIGINT;");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS tg_username     VARCHAR(64);");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS tg_linked_at    TIMESTAMPTZ;");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_at   TIMESTAMPTZ;");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_ip   VARCHAR(45);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_tg_user_id ON users(tg_user_id);");

    // 1.2 user_settings
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_settings (
            id        BIGSERIAL PRIMARY KEY,
            user_id   BIGINT NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
            theme     VARCHAR(10)  DEFAULT 'light',
            timezone  VARCHAR(100) DEFAULT 'UTC',
            fontsize  VARCHAR(20)  DEFAULT 'medium'
        );
    ");

    // 1.3 password_resets
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS password_resets (
            id         BIGSERIAL PRIMARY KEY,
            user_id    BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            token      VARCHAR(64) NOT NULL UNIQUE,
            expires_at TIMESTAMPTZ NOT NULL,
            created_at TIMESTAMPTZ DEFAULT NOW()
        );
    ");

    // 1.4 user_logs
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_logs (
            id         BIGSERIAL PRIMARY KEY,
            user_id    BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            action     VARCHAR(255),
            details    TEXT,
            ip_address VARCHAR(45),
            created_at TIMESTAMPTZ DEFAULT NOW()
        );
    ");

    // 1.5 email_verification
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS email_verification (
            id         BIGSERIAL PRIMARY KEY,
            user_id    BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            token      VARCHAR(255) NOT NULL,
            expires_at TIMESTAMPTZ NOT NULL
        );
    ");

    // 1.6 twofa_codes
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS twofa_codes (
            id         BIGSERIAL PRIMARY KEY,
            user_id    BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            code       VARCHAR(10) NOT NULL,
            expires_at TIMESTAMPTZ NOT NULL
        );
    ");

    /* ============================================================
     * 2) САЙТИ, TELEGRAM, НОТИФІКАЦІЇ
     * ============================================================*/

    // 2.1 sites
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sites (
            id                BIGSERIAL PRIMARY KEY,
            user_id           BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            url               TEXT   NOT NULL,
            title             TEXT,
            telegram_chat_id  VARCHAR(50),
            telegram_type     VARCHAR(20),
            created_at        TIMESTAMPTZ DEFAULT NOW()
        );
    ");
    // додаткові поля
    $pdo->exec("ALTER TABLE sites ADD COLUMN IF NOT EXISTS protect_token     VARCHAR(256);");
    $pdo->exec("ALTER TABLE sites ADD COLUMN IF NOT EXISTS tg_alert_template TEXT;");

    // 2.2 chats
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS chats (
            chat_id    BIGINT PRIMARY KEY,
            title      VARCHAR(255),
            chat_type  VARCHAR(16) CHECK (chat_type IN ('private','group','supergroup','channel')),
            updated_at TIMESTAMPTZ DEFAULT NOW()
        );
    ");
    // тригер на updated_at
    $pdo->exec("
        DO $$
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_chats_set_timestamp') THEN
              CREATE TRIGGER trg_chats_set_timestamp
              BEFORE UPDATE ON chats
              FOR EACH ROW EXECUTE FUNCTION set_timestamp();
            END IF;
        END$$;
    ");

    // 2.3 chat_admins
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS chat_admins (
            chat_id          BIGINT NOT NULL REFERENCES chats(chat_id) ON DELETE CASCADE,
            admin_tg_user_id BIGINT NOT NULL,
            PRIMARY KEY (chat_id, admin_tg_user_id)
        );
    ");

    // 2.4 user_notification_target
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_notification_target (
            id           BIGSERIAL PRIMARY KEY,
            site_user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            chat_id      BIGINT NOT NULL REFERENCES chats(chat_id) ON DELETE CASCADE,
            updated_at   TIMESTAMPTZ DEFAULT NOW()
        );
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_unt_user ON user_notification_target(site_user_id);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_unt_chat ON user_notification_target(chat_id);");
    $pdo->exec("
        DO $$
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_unt_set_timestamp') THEN
              CREATE TRIGGER trg_unt_set_timestamp
              BEFORE UPDATE ON user_notification_target
              FOR EACH ROW EXECUTE FUNCTION set_timestamp();
            END IF;
        END$$;
    ");

    /* ============================================================
     * 3) WAF PODIЇ + ЛОГИ
     * ============================================================*/

    // 3.1 waf_events — “головна” таблиця інцидентів
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS waf_events (
            id         BIGSERIAL PRIMARY KEY,
            site_id    BIGINT NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
            label      VARCHAR(64),
            score      NUMERIC,
            ip         VARCHAR(64),
            ua         TEXT,
            url        TEXT,
            ref        TEXT,
            raw        JSONB,
            created_at TIMESTAMPTZ DEFAULT NOW()
        );
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_waf_events_site_id ON waf_events(site_id);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_waf_events_created_at ON waf_events(created_at);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_waf_events_ip ON waf_events(ip);");

    // 3.2 waf_logs — детальні текстові логи (опціонально, для історії)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS waf_logs (
            id         BIGSERIAL PRIMARY KEY,
            site_id    BIGINT NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
            label      VARCHAR(64),
            score      NUMERIC,
            ua         TEXT,
            url        TEXT,
            ref        TEXT,
            source     VARCHAR(64),
            content    TEXT,
            ip         VARCHAR(45),
            created_at TIMESTAMPTZ DEFAULT NOW()
        );
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_waf_logs_site_id ON waf_logs(site_id);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_waf_logs_created_at ON waf_logs(created_at);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_waf_logs_ip ON waf_logs(ip);");

    /* ============================================================
     * 4) CLIENT INFO (IP + GEO + BROWSER + HEADERS + SERVER + CONNECT)
     * ============================================================*/

    // 4.1 clients — “головна” сутність клієнта
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS clients (
            id              SERIAL PRIMARY KEY,
            ip              VARCHAR(45) NOT NULL,
            country         VARCHAR(100),
            country_code    VARCHAR(10),
            region          VARCHAR(100),
            region_name     VARCHAR(100),
            city            VARCHAR(100),
            zip_code        VARCHAR(20),
            latitude        NUMERIC(10,6),
            longitude       NUMERIC(10,6),
            timezone        VARCHAR(50),
            currency        VARCHAR(10),
            isp             VARCHAR(100),
            organization    VARCHAR(100),
            is_proxy        BOOLEAN,
            request_time    TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW()
        );
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clients_ip ON clients(ip);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clients_request_time ON clients(request_time);");

    // 4.2 browsers
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS browsers (
            id          SERIAL PRIMARY KEY,
            client_id   INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
            name        VARCHAR(100),
            version     VARCHAR(50),
            os          VARCHAR(100),
            device      VARCHAR(100),
            user_agent  TEXT
        );
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_browsers_client_id ON browsers(client_id);");

    // 4.3 headers
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS headers (
            id                SERIAL PRIMARY KEY,
            client_id         INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
            accept_language   VARCHAR(255),
            referer           TEXT,
            accept_encoding   VARCHAR(100),
            cache_control     VARCHAR(100),
            connection        VARCHAR(100)
        );
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_headers_client_id ON headers(client_id);");

    // 4.4 connection_info
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS connection_info (
            id               SERIAL PRIMARY KEY,
            client_id        INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
            remote_port      INTEGER,
            server_port      INTEGER,
            request_scheme   VARCHAR(10),
            server_protocol  VARCHAR(20)
        );
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_connection_client_id ON connection_info(client_id);");

    // 4.5 server_info
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS server_info (
            id               SERIAL PRIMARY KEY,
            client_id        INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
            server_software  VARCHAR(255),
            server_name      VARCHAR(255),
            server_addr      VARCHAR(45),
            request_method   VARCHAR(10),
            request_time     TIMESTAMP WITHOUT TIME ZONE
        );
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_serverinfo_client_id ON server_info(client_id);");

    /* ============================================================
     * 5) GEO CACHE
     * ============================================================*/

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS geo_cache (
            ip         VARCHAR(64) PRIMARY KEY,
            payload    JSONB NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        );
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_geo_cache_updated_at ON geo_cache(updated_at DESC);");

    $pdo->commit();
    echo "✅ Міграції для PostgreSQL виконано\\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    exit('❌ Помилка міграції (PostgreSQL): ' . $e->getMessage());
}
