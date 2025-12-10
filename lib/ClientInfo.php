<?php
declare(strict_types=1);

final class ClientInfo
{
    /** Безпечне читання HTTP-заголовка */
    private static function hdr(string $name): string {
        return isset($_SERVER[$name]) ? trim((string)$_SERVER[$name]) : '';
    }

    /** IP клієнта (з урахуванням проксі) */
    public static function clientIp(): string {
        $ip = self::hdr('HTTP_CF_CONNECTING_IP') ?: self::hdr('HTTP_X_FORWARDED_FOR') ?: self::hdr('REMOTE_ADDR');
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    /** Публічний IP сервера (іноді корисно) – через ipify з таймаутом */
    public static function fetchServerPublicIp(int $timeoutMs = 1200): ?string {
        $url = 'https://api64.ipify.org';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_FAILONERROR       => true,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            CURLOPT_TIMEOUT_MS        => $timeoutMs + 800,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $ip = is_string($resp) ? trim($resp) : '';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    /** Акуратний HTTP GET (з таймаутами) */
    private static function httpGet(string $url, int $timeoutMs = 1200): ?string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_FAILONERROR       => false,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            CURLOPT_TIMEOUT_MS        => $timeoutMs + 800,
            CURLOPT_HTTPHEADER        => ['Accept: application/json'],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $code >= 400) return null;
        return $resp;
    }

    /**
     * Гео по IP (через ip-api.com), з мінімальним набором полів.
     */
    public static function geoByIp(string $ip): ?array {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return null;
        $q = http_build_query([
            'fields' => 'status,message,continent,continentCode,country,countryCode,region,regionName,city,zip,lat,lon,timezone,currency,isp,org,as,asname,proxy,hosting,query',
        ]);
        $resp = self::httpGet("http://ip-api.com/json/{$ip}?{$q}");
        if (!$resp) return null;
        $j = json_decode($resp, true);
        if (!is_array($j) || ($j['status'] ?? '') !== 'success') return null;

        return [
            'ip'            => $j['query']         ?? $ip,
            'country'       => $j['country']       ?? null,
            'country_code'  => $j['countryCode']   ?? null,
            'region'        => $j['regionName']    ?? null,
            'city'          => $j['city']          ?? null,
            'zip'           => $j['zip']           ?? null,
            'lat'           => $j['lat']           ?? null,
            'lon'           => $j['lon']           ?? null,
            'timezone'      => $j['timezone']      ?? null,
            'isp'           => $j['isp']           ?? null,
            'org'           => $j['org']           ?? null,
            'asname'        => $j['asname']        ?? null,
            'proxy'         => (bool)($j['proxy']  ?? false),
            'hosting'       => (bool)($j['hosting']?? false),
        ];
    }

    /** Парсер User-Agent (спрощений) */
    public static function parseUserAgent(?string $ua = null): array {
        $ua = $ua ?? self::hdr('HTTP_USER_AGENT');
        $ua = (string)$ua;

        $browser = 'Unknown'; $version = ''; $os = 'Unknown'; $device = 'Desktop';

        $browsers = [
            'Edg'     => 'Microsoft Edge',
            'OPR'     => 'Opera',
            'Chrome'  => 'Google Chrome',
            'Firefox' => 'Mozilla Firefox',
            'Safari'  => 'Apple Safari',
            'MSIE'    => 'Internet Explorer',
            'Trident' => 'Internet Explorer',
            'Brave'   => 'Brave',
        ];
        foreach ($browsers as $sig => $name) {
            if (stripos($ua, $sig) !== false) {
                $browser = $name;
                if (preg_match('~'.$sig.'/?([0-9.]+)~i', $ua, $m)) $version = $m[1];
                break;
            }
        }

        $oses = [
            'Windows NT 10'   => 'Windows 10/11',
            'Windows NT 6.3'  => 'Windows 8.1',
            'Windows NT 6.2'  => 'Windows 8',
            'Windows NT 6.1'  => 'Windows 7',
            'Mac OS X'        => 'macOS',
            'Android'         => 'Android',
            'iPhone|iPad|iPod'=> 'iOS',
            'Linux'           => 'Linux',
        ];
        foreach ($oses as $pat => $name) {
            if (preg_match('~'.$pat.'~i', $ua)) { $os = $name; break; }
        }

        if (preg_match('~Mobile|Android|iPhone|iPad|iPod~i', $ua)) $device = 'Mobile';

        return [
            'name'       => $browser,
            'version'    => $version,
            'os'         => $os,
            'device'     => $device,
            'user_agent' => $ua,
        ];
    }

    /** Заголовки/конект/сервер */
    public static function requestMeta(): array {
        return [
            'headers' => [
                'accept_language' => self::hdr('HTTP_ACCEPT_LANGUAGE') ?: null,
                'referer'         => self::hdr('HTTP_REFERER') ?: null,
                'accept_encoding' => self::hdr('HTTP_ACCEPT_ENCODING') ?: null,
                'cache_control'   => self::hdr('HTTP_CACHE_CONTROL') ?: null,
                'connection'      => self::hdr('HTTP_CONNECTION') ?: null,
            ],
            'connection' => [
                'remote_port'    => self::hdr('REMOTE_PORT') ?: null,
                'server_port'    => self::hdr('SERVER_PORT') ?: null,
                'request_scheme' => self::hdr('REQUEST_SCHEME') ?: null,
                'server_protocol'=> self::hdr('SERVER_PROTOCOL') ?: null,
            ],
            'server' => [
                'server_software'=> self::hdr('SERVER_SOFTWARE') ?: null,
                'server_name'    => self::hdr('SERVER_NAME') ?: null,
                'server_addr'    => self::hdr('SERVER_ADDR') ?: null,
                'request_time'   => (string)($_SERVER['REQUEST_TIME'] ?? ''),
                'request_method' => self::hdr('REQUEST_METHOD') ?: null,
            ],
        ];
    }

    /** Усе разом (без гео). */
    public static function basic(): array {
        $meta = self::requestMeta();
        return [
            'ip'         => self::clientIp(),
            'ua'         => self::parseUserAgent(),
            'headers'    => $meta['headers'],
            'connection' => $meta['connection'],
            'server'     => $meta['server'],
        ];
    }

    /**
     * Зібрати все і записати в:
     *  - clients (з site_id)
     *  - browsers
     *  - headers
     *  - connection_info
     *  - server_info
     */
    public static function collectAndStore(\PDO $pdo, string $ip, int $eventId = 0, int $siteId = 0): void
    {
        $logFile = __DIR__ . '/../waf/waf_debug.log';
        @file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] CLIENTINFO_IP_IN: {$ip}\n", FILE_APPEND);
        // IP нам уже дав waf_log.php
        $ip = trim($ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return;
        }

        // Базові дані (UA, headers, server, connection)
        $base = self::basic();
        $base['ip'] = $ip; // на всяк випадок синхронізуємо

        // GEO (з кешем, якщо є GeoCache)
        $geo = null;
        if (class_exists('GeoCache')) {
            $geo = GeoCache::get($ip, 3600);
        }
        if (!$geo) {
            $geo = self::geoByIp($ip);
            if ($geo && class_exists('GeoCache')) {
                GeoCache::put($ip, $geo);
            }
        }
        if (!$geo) {
            $geo = ['ip' => $ip];
        }

        // === clients (з site_id) ===
        $stmt = $pdo->prepare("
        INSERT INTO clients (
            site_id,
            ip,
            country,
            country_code,
            region,
            region_name,
            city,
            zip_code,
            latitude,
            longitude,
            timezone,
            currency,
            isp,
            organization,
            is_proxy,
            request_time
        ) VALUES (
            :site_id,
            :ip,
            :country,
            :country_code,
            :region,
            :region_name,
            :city,
            :zip_code,
            :latitude,
            :longitude,
            :timezone,
            :currency,
            :isp,
            :organization,
            :is_proxy,
            NOW()
        )
        ON CONFLICT (site_id, ip) DO UPDATE SET
            country      = EXCLUDED.country,
            country_code = EXCLUDED.country_code,
            region       = EXCLUDED.region,
            region_name  = EXCLUDED.region_name,
            city         = EXCLUDED.city,
            zip_code     = EXCLUDED.zip_code,
            latitude     = EXCLUDED.latitude,
            longitude    = EXCLUDED.longitude,
            timezone     = EXCLUDED.timezone,
            currency     = EXCLUDED.currency,
            isp          = EXCLUDED.isp,
            organization = EXCLUDED.organization,
            is_proxy     = EXCLUDED.is_proxy,
            request_time = NOW()
        RETURNING id
    ");

        $stmt->execute([
            ':site_id'      => $siteId ?: null,
            ':ip'           => $geo['ip']            ?? $ip,
            ':country'      => $geo['country']       ?? null,
            ':country_code' => $geo['country_code']  ?? null,
            ':region'       => $geo['region']        ?? null,
            ':region_name'  => $geo['region']        ?? null,
            ':city'         => $geo['city']          ?? null,
            ':zip_code'     => $geo['zip']           ?? null,
            ':latitude'     => $geo['lat']           ?? null,
            ':longitude'    => $geo['lon']           ?? null,
            ':timezone'     => $geo['timezone']      ?? null,
            ':currency'     => $geo['currency']      ?? null,
            ':isp'          => $geo['isp']           ?? null,
            ':organization' => $geo['org']           ?? null,
            ':is_proxy'     => isset($geo['proxy'])
                ? ($geo['proxy'] ? 1 : 0)
                : null,
        ]);


        $clientId = (int)$stmt->fetchColumn();
        if ($clientId <= 0) {
            return;
        }

        // === browsers ===
        $ua = isset($base['ua']) && is_array($base['ua'])
            ? $base['ua']
            : [
                'name'       => null,
                'version'    => null,
                'os'         => null,
                'device'     => null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ];

        $stmt = $pdo->prepare("
        INSERT INTO browsers (
            client_id,
            name,
            version,
            os,
            device,
            user_agent
        ) VALUES (
            :client_id,
            :name,
            :version,
            :os,
            :device,
            :user_agent
        )
    ");
        $stmt->execute([
            ':client_id'  => $clientId,
            ':name'       => $ua['name']       ?? null,
            ':version'    => $ua['version']    ?? null,
            ':os'         => $ua['os']         ?? null,
            ':device'     => $ua['device']     ?? null,
            ':user_agent' => $ua['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null),
        ]);

        // === headers ===
        $h = isset($base['headers']) && is_array($base['headers'])
            ? $base['headers']
            : [];

        $stmt = $pdo->prepare("
        INSERT INTO headers (
            client_id,
            accept_language,
            referer,
            accept_encoding,
            cache_control,
            connection
        ) VALUES (
            :client_id,
            :accept_language,
            :referer,
            :accept_encoding,
            :cache_control,
            :connection
        )
    ");
        $stmt->execute([
            ':client_id'       => $clientId,
            ':accept_language' => $h['accept_language'] ?? ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null),
            ':referer'         => $h['referer']         ?? ($_SERVER['HTTP_REFERER'] ?? null),
            ':accept_encoding' => $h['accept_encoding'] ?? ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? null),
            ':cache_control'   => $h['cache_control']   ?? ($_SERVER['HTTP_CACHE_CONTROL'] ?? null),
            ':connection'      => $h['connection']      ?? ($_SERVER['HTTP_CONNECTION'] ?? null),
        ]);

        // === connection_info ===
        $c = isset($base['connection']) && is_array($base['connection'])
            ? $base['connection']
            : [];

        $stmt = $pdo->prepare("
        INSERT INTO connection_info (
            client_id,
            remote_port,
            server_port,
            request_scheme,
            server_protocol
        ) VALUES (
            :client_id,
            :remote_port,
            :server_port,
            :request_scheme,
            :server_protocol
        )
    ");
        $stmt->execute([
            ':client_id'      => $clientId,
            ':remote_port'    => $c['remote_port']    ?? ($_SERVER['REMOTE_PORT'] ?? null),
            ':server_port'    => $c['server_port']    ?? ($_SERVER['SERVER_PORT'] ?? null),
            ':request_scheme' => $c['request_scheme'] ?? (
                (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http'
                ),
            ':server_protocol'=> $c['server_protocol']?? ($_SERVER['SERVER_PROTOCOL'] ?? null),
        ]);

        // === server_info ===
        $s = isset($base['server']) && is_array($base['server'])
            ? $base['server']
            : [];

        $stmt = $pdo->prepare("
        INSERT INTO server_info (
            client_id,
            server_software,
            server_name,
            server_addr,
            request_method,
            request_time
        ) VALUES (
            :client_id,
            :server_software,
            :server_name,
            :server_addr,
            :request_method,
            NOW()
        )
    ");
        $stmt->execute([
            ':client_id'       => $clientId,
            ':server_software' => $s['server_software'] ?? ($_SERVER['SERVER_SOFTWARE'] ?? null),
            ':server_name'     => $s['server_name']     ?? ($_SERVER['SERVER_NAME'] ?? null),
            ':server_addr'     => $s['server_addr']     ?? ($_SERVER['SERVER_ADDR'] ?? null),
            ':request_method'  => $s['request_method']  ?? ($_SERVER['REQUEST_METHOD'] ?? null),
        ]);
    }
}
