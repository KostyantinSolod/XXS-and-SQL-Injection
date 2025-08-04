<?php
require_once __DIR__ . '/db.php';  // Підключаємо db.php, який працює з .env

$pdo = $pdo;  // Отримуємо підключення з db.php

try {
    $queries = [

        // 1. Таблиця користувачів
        "CREATE TABLE IF NOT EXISTS users (
            id SERIAL PRIMARY KEY,
            username VARCHAR(255) NOT NULL UNIQUE,
            email VARCHAR(255) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            reset_token VARCHAR(255),
            reset_expires TIMESTAMP,
            email_verified BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",

        // 2. Налаштування користувачів
        "CREATE TABLE IF NOT EXISTS user_settings (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE UNIQUE,
            theme VARCHAR(10) DEFAULT 'light',
            timezone VARCHAR(100) DEFAULT 'UTC',
            fontsize VARCHAR(20) DEFAULT 'medium'
        )",



        // 3. Відновлення паролів (окремо)
        "CREATE TABLE IF NOT EXISTS password_resets (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            token VARCHAR(64) NOT NULL UNIQUE,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",

        // 4. Лог дій користувача
        "CREATE TABLE IF NOT EXISTS user_logs (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            action VARCHAR(255),
            details TEXT,
            ip_address VARCHAR(45),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",

        // 5. Email-підтвердження
        "CREATE TABLE IF NOT EXISTS email_verification (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            token VARCHAR(255) NOT NULL,
            expires_at TIMESTAMP NOT NULL
        )",

        // 6. 2FA-коди (по email)
        "CREATE TABLE IF NOT EXISTS twofa_codes (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            code VARCHAR(10) NOT NULL,
            expires_at TIMESTAMP NOT NULL
        )",

        // 7. Таблиця клієнтів
        "CREATE TABLE IF NOT EXISTS clients (
            id SERIAL PRIMARY KEY,
            ip VARCHAR(45),
            country VARCHAR(100),
            country_code VARCHAR(10),
            region VARCHAR(100),
            region_name VARCHAR(100),
            city VARCHAR(100),
            zip_code VARCHAR(20),
            latitude DECIMAL(10, 6),
            longitude DECIMAL(10, 6),
            timezone VARCHAR(50),
            currency VARCHAR(10),
            isp VARCHAR(100),
            organization VARCHAR(100),
            is_proxy BOOLEAN DEFAULT false,
            request_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",

        // 8. Інформація про браузери
        "CREATE TABLE IF NOT EXISTS browsers (
            id SERIAL PRIMARY KEY,
            client_id INTEGER REFERENCES clients(id) ON DELETE CASCADE,
            name VARCHAR(100),
            version VARCHAR(50),
            os VARCHAR(100),
            device VARCHAR(50),
            user_agent TEXT
        )",

        // 9. Заголовки HTTP
        "CREATE TABLE IF NOT EXISTS headers (
            id SERIAL PRIMARY KEY,
            client_id INTEGER REFERENCES clients(id) ON DELETE CASCADE,
            accept_language VARCHAR(255),
            referer TEXT,
            connection VARCHAR(50),
            accept_encoding VARCHAR(100),
            cache_control VARCHAR(100)
        )",

        // 10. Інфо про з'єднання
        "CREATE TABLE IF NOT EXISTS connection_info (
            id SERIAL PRIMARY KEY,
            client_id INTEGER REFERENCES clients(id) ON DELETE CASCADE,
            remote_port INTEGER,
            server_port INTEGER,
            request_scheme VARCHAR(10),
            server_protocol VARCHAR(20)
        )",

        // 11. Інфо про сервер
        "CREATE TABLE IF NOT EXISTS server_info (
            id SERIAL PRIMARY KEY,
            client_id INTEGER REFERENCES clients(id) ON DELETE CASCADE,
            server_software VARCHAR(255),
            server_name VARCHAR(255),
            server_addr VARCHAR(45),
            request_method VARCHAR(10),
            request_time TIMESTAMP
        )",

        // 12. Сайти
        "CREATE TABLE IF NOT EXISTS sites (
            id SERIAL PRIMARY KEY,
            user_id INT REFERENCES users(id) ON DELETE CASCADE,
            url TEXT NOT NULL,
            title TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    ];

    foreach ($queries as $query) {
        $pdo->exec($query);
    }

    echo "✅ База даних успішно ініціалізована!";

} catch (PDOException $e) {
    die("❌ Помилка при створенні таблиць: " . $e->getMessage());
}
?>
