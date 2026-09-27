<?php
if (!defined('APP_ROOT')) exit;

/**
 * 备份 / 还原插件（backup）。
 *
 * 零核心改动：
 * - 按当前数据库驱动自动选择策略（SQLite / MySQL / PostgreSQL）：
 *   SQLite 用 `VACUUM INTO` 生成一致性快照（兼容 WAL，失败退回文件复制），支持整库一键还原；
 *   MySQL / PostgreSQL 导出「全表 JSON 数据快照」（结构不备份），还原时按表覆盖数据：
 *   目标库缺失的表自动跳过并提示，列不一致时按同名列取交集写入。
 * - 备份文件落盘 DATA_DIR/backups/，按保留份数自动清理旧文件。
 * - 渠道（可同时启用）：邮箱（原生 SMTP + MIME 附件，支持 SSL/STARTTLS）、WebDAV（PUT 上传）。
 * - cron scheduled：按配置间隔（每小时/6 小时/每天/每周）自动备份并推送渠道。
 * - 后台：立即备份、文件列表（下载 / 还原 / 删除）、上传还原、渠道与计划配置。
 * - route backup_download：管理员下载本地备份文件（文件名白名单校验）。
 *
 * 安全：还原前自动把当前数据库留存为 pre-restore-*.sqlite；所有回调 try/catch 兜底，
 * 异常不抛出（避免核心自动停用插件）。
 */

// 归一化配置（旧配置缺字段不报错）。
function backup_config(): array
{
    $raw = plugin_config('backup', []);
    $interval = (string)($raw['schedule_interval'] ?? 'daily');
    if (!in_array($interval, ['hourly', '6h', 'daily', 'weekly'], true)) $interval = 'daily';
    $secure = (string)($raw['smtp_secure'] ?? 'ssl');
    if (!in_array($secure, ['ssl', 'starttls', 'none'], true)) $secure = 'ssl';
    return [
        'schedule_enabled' => (int)($raw['schedule_enabled'] ?? 0) === 1,
        'schedule_interval' => $interval,
        'keep_files' => min(50, max(1, (int)($raw['keep_files'] ?? 10))),
        'email_enabled' => (int)($raw['email_enabled'] ?? 0) === 1,
        'smtp_host' => (string)($raw['smtp_host'] ?? ''),
        'smtp_port' => min(65535, max(1, (int)($raw['smtp_port'] ?? 465))),
        'smtp_secure' => $secure,
        'smtp_user' => (string)($raw['smtp_user'] ?? ''),
        'smtp_pass' => (string)($raw['smtp_pass'] ?? ''),
        'mail_from' => (string)($raw['mail_from'] ?? ''),
        'mail_to' => (string)($raw['mail_to'] ?? ''),
        'webdav_enabled' => (int)($raw['webdav_enabled'] ?? 0) === 1,
        'webdav_url' => (string)($raw['webdav_url'] ?? ''),
        'webdav_user' => (string)($raw['webdav_user'] ?? ''),
        'webdav_pass' => (string)($raw['webdav_pass'] ?? ''),
    ];
}

function backup_install(array $plugin): void
{
    backup_ensure_dir();
}

function backup_uninstall(array $plugin, bool $keep_data = true): void
{
    if ($keep_data) return;
    foreach (backup_list_files() as $f) {
        if (str_starts_with($f['name'], 'backup-')) @unlink($f['path']);
    }
}

function backup_dir(): string { return DATA_DIR . '/backups'; }
function backup_ensure_dir(): bool
{
    $dir = backup_dir();
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return is_dir($dir) && is_writable($dir);
}

// 合法备份文件名（含还原留底文件），返回绝对路径，非法返回空串。
function backup_file_by_name(string $name): string
{
    if (preg_match('/^(backup|pre-restore)-\d{8}-\d{6}(-[a-f0-9]{6})?\.(sqlite|json)$/', $name) !== 1) return '';
    $path = backup_dir() . '/' . $name;
    return is_file($path) ? $path : '';
}

// 本地备份文件列表（按时间倒序）。
function backup_list_files(): array
{
    $items = [];
    foreach (glob(backup_dir() . '/*') ?: [] as $path) {
        if (!is_file($path)) continue;
        $name = basename($path);
        if (!preg_match('/^(backup|pre-restore)-\d{8}-\d{6}(-[a-f0-9]{6})?\.(sqlite|json)$/', $name)) continue;
        $items[] = ['name' => $name, 'path' => $path, 'size' => (int)filesize($path), 'time' => (int)filemtime($path)];
    }
    usort($items, static fn(array $a, array $b): int => $b['time'] <=> $a['time']);
    return $items;
}

function backup_size_human(int $bytes): string
{
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

// 当前数据库全部表名（标识符白名单过滤）。
function backup_list_tables(): array
{
    $tables = [];
    try {
        if (db_driver() === 'sqlite') {
            foreach (all("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") as $r) $tables[] = (string)$r['name'];
        } elseif (db_driver() === 'mysql') {
            foreach (all('SHOW TABLES') as $r) $tables[] = (string)reset($r);
        } else {
            foreach (all("SELECT tablename FROM pg_tables WHERE schemaname='public'") as $r) $tables[] = (string)$r['tablename'];
        }
    } catch (\Throwable) {
        return [];
    }
    return array_values(array_filter($tables, static fn(mixed $t): bool => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string)$t) === 1));
}

// 当前数据库中指定表的列名（表不存在或查询异常时返回空数组）。
function backup_table_columns(string $table): array
{
    $cols = [];
    try {
        if (db_driver() === 'sqlite') {
            foreach (all('PRAGMA table_info(' . app_db_identifier('sqlite', $table) . ')') as $r) $cols[] = (string)$r['name'];
        } elseif (db_driver() === 'mysql') {
            foreach (all('SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]) as $r) $cols[] = (string)$r['c'];
        } else {
            foreach (all("SELECT column_name AS c FROM information_schema.columns WHERE table_schema='public' AND table_name=?", [$table]) as $r) $cols[] = (string)$r['c'];
        }
    } catch (\Throwable) {
        return [];
    }
    return $cols;
}

// 生成备份文件。返回 [ok, 文件路径, 错误信息]。
function backup_create(): array
{
    if (!backup_ensure_dir()) return [false, '', '备份目录不可写：' . backup_dir()];
    $stamp = date('Ymd-His');
    $rand = bin2hex(random_bytes(3));

    if (db_driver() === 'sqlite') {
        $file = backup_dir() . '/backup-' . $stamp . '-' . $rand . '.sqlite';
        $source = (string)(db_config()['path'] ?? '');
        if ($source === '' || !is_file($source)) return [false, '', '找不到 SQLite 数据库文件'];
        try {
            // VACUUM INTO 生成一致性快照（自动包含 WAL 中未落盘的数据）。
            db()->exec('VACUUM INTO ' . db()->quote($file));
        } catch (\Throwable) {
            try { db()->exec('PRAGMA wal_checkpoint(TRUNCATE)'); } catch (\Throwable) {}
            if (!@copy($source, $file)) return [false, '', '复制数据库文件失败（请检查目录写权限）'];
        }
        if (!is_file($file) || filesize($file) < 1) return [false, '', '备份文件生成失败'];
        return [true, $file, ''];
    }

    // 非 SQLite：导出全表 JSON 数据快照。
    $file = backup_dir() . '/backup-' . $stamp . '-' . $rand . '.json';
    try {
        $tables = [];
        foreach (backup_list_tables() as $t) $tables[$t] = all('SELECT * FROM ' . app_db_identifier(db_driver(), $t));
        $payload = ['format' => 'mono-backup', 'version' => 1, 'driver' => db_driver(), 'created_at' => now(), 'tables' => $tables];
        if (file_put_contents($file, (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
            return [false, '', '写入备份文件失败'];
        }
    } catch (\Throwable $e) {
        @unlink($file);
        return [false, '', '导出数据失败：' . $e->getMessage()];
    }
    return [true, $file, ''];
}

// 仅保留最近 N 份 backup-*（pre-restore-* 留底文件不参与清理）。
function backup_prune(int $keep): void
{
    $files = array_values(array_filter(backup_list_files(), static fn(array $f): bool => str_starts_with($f['name'], 'backup-')));
    if (count($files) <= $keep) return;
    foreach (array_slice($files, $keep) as $f) @unlink($f['path']);
}

// --- 渠道分发 ---
function backup_dispatch(array $cfg, string $file): array
{
    $results = [];
    if ($cfg['email_enabled']) $results[] = ['channel' => '邮箱', 'result' => backup_send_email($cfg, $file)];
    if ($cfg['webdav_enabled']) $results[] = ['channel' => 'WebDAV', 'result' => backup_send_webdav($cfg, $file)];
    if (!$results) $results[] = ['channel' => '本地', 'result' => [true, '仅保存到本地（未启用推送渠道）']];
    return $results;
}

// 执行一次完整备份（生成 + 推送 + 清理 + 记录），返回 [是否全部成功, 摘要]。
function backup_run(array $cfg): array
{
    [$ok, $file, $err] = backup_create();
    if (!$ok) {
        backup_record_last(false, '', '备份失败：' . $err);
        return [false, '备份失败：' . $err];
    }
    $lines = [];
    $all_ok = true;
    foreach (backup_dispatch($cfg, $file) as $item) {
        [$cok, $cmsg] = $item['result'];
        if (!$cok) $all_ok = false;
        $lines[] = ($cok ? '✓' : '✗') . ' ' . $item['channel'] . '：' . $cmsg;
    }
    backup_prune($cfg['keep_files']);
    backup_record_last($all_ok, basename($file), implode('；', $lines));
    return [$all_ok, basename($file) . '（' . implode('；', $lines) . '）'];
}

function backup_record_last(bool $ok, string $file, string $message): void
{
    try {
        save_settings_values(['plugin_backup_last' => (string)json_encode([
            'time' => now(),
            'ok' => $ok,
            'file' => $file,
            'message' => cut($message, 300),
        ], JSON_UNESCAPED_UNICODE)]);
    } catch (\Throwable) {
    }
}

function backup_last_run(): array
{
    $data = json_decode(setting('plugin_backup_last', ''), true);
    return is_array($data) ? $data : [];
}

// --- 邮箱渠道（原生 SMTP，支持 ssl / starttls / 明文）---
function backup_send_email(array $cfg, string $file): array
{
    if ($cfg['smtp_host'] === '' || $cfg['mail_to'] === '') return [false, '未配置完整（需要 SMTP 服务器与收件人）'];
    if (!function_exists('stream_socket_client')) return [false, '服务器不支持 SMTP（stream_socket_client 不可用）'];
    $data = @file_get_contents($file);
    if ($data === false) return [false, '读取备份文件失败'];
    $name = basename($file);
    $site = trim(setting('site_name', 'Mono')) ?: 'Mono';
    $subject = '[' . $site . '] 数据库备份 ' . date('Y-m-d H:i');
    $body = $site . " 的自动备份：\n\n文件：" . $name . "\n大小：" . backup_size_human((int)strlen($data)) . "\n时间：" . date('Y-m-d H:i:s')
        . "\n\n（附件为数据库备份文件，请妥善保存。）";
    try {
        backup_smtp_send($cfg, $site, $subject, $body, $name, $data);
        return [true, '已发送至 ' . $cfg['mail_to']];
    } catch (\Throwable $e) {
        return [false, '发送失败：' . $e->getMessage()];
    }
}

function backup_smtp_send(array $cfg, string $from_name, string $subject, string $body, string $attach_name, string $attach_data): void
{
    $transport = $cfg['smtp_secure'] === 'ssl' ? 'ssl://' : 'tcp://';
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client($transport . $cfg['smtp_host'] . ':' . $cfg['smtp_port'], $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new \RuntimeException('无法连接 ' . $cfg['smtp_host'] . ':' . $cfg['smtp_port'] . ($errstr !== '' ? '（' . $errstr . '）' : ''));
    stream_set_timeout($fp, 20);

    $read = function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 2048)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        return trim($out);
    };
    $expect = function (string $resp, string $code): void {
        if (strncmp($resp, $code, strlen($code)) !== 0) throw new \RuntimeException('SMTP 响应异常：' . $resp);
    };
    $cmd = function (string $line, ?string $code = null) use ($fp, $read, $expect): string {
        fwrite($fp, $line . "\r\n");
        $resp = $read();
        if ($code !== null) $expect($resp, $code);
        return $resp;
    };

    try {
        $expect($read(), '220');
        $helo = (string)(parse_url(absolute_url(route_url('home')), PHP_URL_HOST) ?: 'mono.local');
        $cmd('EHLO ' . $helo, '250');
        if ($cfg['smtp_secure'] === 'starttls') {
            $cmd('STARTTLS', '220');
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new \RuntimeException('STARTTLS 协商失败');
            $cmd('EHLO ' . $helo, '250');
        }
        if ($cfg['smtp_user'] !== '' || $cfg['smtp_pass'] !== '') {
            $cmd('AUTH LOGIN', '334');
            $cmd(base64_encode($cfg['smtp_user']), '334');
            $cmd(base64_encode($cfg['smtp_pass']), '235');
        }
        $from = $cfg['mail_from'] !== '' ? $cfg['mail_from'] : $cfg['smtp_user'];
        if ($from === '') throw new \RuntimeException('缺少发件人地址');
        $cmd('MAIL FROM:<' . $from . '>', '250');
        $recipients = array_values(array_filter(array_map('trim', preg_split('/[,;]/', $cfg['mail_to']) ?: [])));
        if (!$recipients) throw new \RuntimeException('收件人为空');
        foreach ($recipients as $to) $cmd('RCPT TO:<' . $to . '>', '250');
        $cmd('DATA', '354');
        fwrite($fp, backup_mime_message($from, $from_name, $recipients, $subject, $body, $attach_name, $attach_data) . "\r\n.\r\n");
        $expect($read(), '250');
        $cmd('QUIT');
    } finally {
        @fclose($fp);
    }
}

// 组装 multipart/mixed 邮件（正文 + base64 附件；base64 行不含「.」开头的行，无需 dot-stuffing）。
function backup_mime_message(string $from, string $from_name, array $to, string $subject, string $body, string $attach_name, string $attach_data): string
{
    $boundary = 'mono-' . bin2hex(random_bytes(8));
    $headers = 'From: =?UTF-8?B?' . base64_encode($from_name) . '?= <' . $from . ">\r\n"
        . 'To: ' . implode(', ', $to) . "\r\n"
        . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
        . 'Date: ' . date('r') . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . 'Content-Type: multipart/mixed; boundary="' . $boundary . "\"\r\n";
    $parts = '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($body), 76, "\r\n");
    $parts .= '--' . $boundary . "\r\nContent-Type: application/octet-stream; name=\"" . $attach_name . "\"\r\n"
        . "Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"" . $attach_name . "\"\r\n\r\n"
        . chunk_split(base64_encode($attach_data), 76, "\r\n");
    $parts .= '--' . $boundary . '--';
    return $headers . "\r\n" . $parts;
}

// --- WebDAV 渠道（curl PUT，stream 兜底）---
function backup_send_webdav(array $cfg, string $file): array
{
    if ($cfg['webdav_url'] === '') return [false, '未配置目录 URL'];
    $name = basename($file);
    $url = rtrim($cfg['webdav_url'], '/') . '/' . rawurlencode($name);
    [$ok, $status, $err] = backup_webdav_put($cfg, $url, $file);
    if (!$ok && $status === 409) {
        // 目录不存在：逐级 MKCOL 后重试一次。
        backup_webdav_mkcol($cfg);
        [$ok, $status, $err] = backup_webdav_put($cfg, $url, $file);
    }
    if ($ok) return [true, '已上传 ' . $name];
    if ($status === 401 || $status === 403) return [false, '认证失败（HTTP ' . $status . '），请检查账号密码'];
    return [false, '上传失败（HTTP ' . $status . ($err !== '' ? '：' . $err : '') . '）'];
}

function backup_webdav_request(array $cfg, string $url, string $method, ?string $body): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPAUTH => CURLAUTH_ANY,
        ];
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
        if ($cfg['webdav_user'] !== '') $opts[CURLOPT_USERPWD] = $cfg['webdav_user'] . ':' . $cfg['webdav_pass'];
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = $resp === false ? (string)curl_error($ch) : '';
        curl_close($ch);
        return [$err === '' && $status >= 200 && $status < 300, $status, $err];
    }
    $ctx = stream_context_create(['http' => [
        'method' => $method,
        'header' => $cfg['webdav_user'] !== '' ? ('Authorization: Basic ' . base64_encode($cfg['webdav_user'] . ':' . $cfg['webdav_pass'])) : '',
        'content' => (string)$body,
        'timeout' => 60,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ((array)($http_response_header ?? []) as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$line, $m)) $status = (int)$m[1];
    }
    return [$resp !== false && $status >= 200 && $status < 300, $status, $resp === false ? '网络错误' : ''];
}

function backup_webdav_put(array $cfg, string $url, string $file): array
{
    if (function_exists('curl_init')) {
        $fh = @fopen($file, 'rb');
        if (!$fh) return [false, 0, '读取备份文件失败'];
        $ch = curl_init($url);
        $opts = [
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $fh,
            CURLOPT_INFILESIZE => (int)filesize($file),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTPAUTH => CURLAUTH_ANY,
        ];
        if ($cfg['webdav_user'] !== '') $opts[CURLOPT_USERPWD] = $cfg['webdav_user'] . ':' . $cfg['webdav_pass'];
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = $resp === false ? (string)curl_error($ch) : '';
        curl_close($ch);
        fclose($fh);
        return [$err === '' && $status >= 200 && $status < 300, $status, $err];
    }
    $data = @file_get_contents($file);
    if ($data === false) return [false, 0, '读取备份文件失败'];
    $headers = "Content-Type: application/octet-stream\r\n";
    if ($cfg['webdav_user'] !== '') $headers .= 'Authorization: Basic ' . base64_encode($cfg['webdav_user'] . ':' . $cfg['webdav_pass']) . "\r\n";
    $ctx = stream_context_create(['http' => ['method' => 'PUT', 'header' => $headers, 'content' => $data, 'timeout' => 300, 'ignore_errors' => true]]);
    $resp = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ((array)($http_response_header ?? []) as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$line, $m)) $status = (int)$m[1];
    }
    return [$resp !== false && $status >= 200 && $status < 300, $status, $resp === false ? '网络错误' : ''];
}

// 逐级创建 WebDAV 目录（已存在时服务器返回 405，忽略即可）。
function backup_webdav_mkcol(array $cfg): void
{
    $parts = parse_url(rtrim($cfg['webdav_url'], '/'));
    if (!$parts || !isset($parts['host'])) return;
    $base = ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
    $built = '';
    foreach (array_values(array_filter(explode('/', (string)($parts['path'] ?? '')), 'strlen')) as $seg) {
        $built .= '/' . $seg;
        backup_webdav_request($cfg, $base . $built, 'MKCOL', null);
    }
}

// --- 还原 ---
// SQLite 还原：校验文件 → 当前库留底 pre-restore → 原子替换（含清理 WAL/SHM）。
function backup_restore_sqlite(string $source): array
{
    if (db_driver() !== 'sqlite') return [false, '当前数据库不是 SQLite，请上传 JSON 数据快照还原'];
    $head = (string)@file_get_contents($source, false, null, 0, 16);
    if (strncmp($head, "SQLite format 3\0", 16) !== 0) return [false, '不是有效的 SQLite 数据库文件'];
    try {
        $test = new PDO('sqlite:' . $source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $test->query('SELECT COUNT(*) FROM sqlite_master')->fetchColumn();
        $test = null;
    } catch (\Throwable $e) {
        return [false, '数据库文件校验失败：' . $e->getMessage()];
    }
    $target = (string)(db_config()['path'] ?? '');
    if ($target === '' || !is_file($target)) return [false, '找不到当前数据库文件'];
    if (!backup_ensure_dir()) return [false, '备份目录不可写，无法留存当前数据库'];
    try { db()->exec('PRAGMA wal_checkpoint(TRUNCATE)'); } catch (\Throwable) {}
    $pre = backup_dir() . '/pre-restore-' . date('Ymd-His') . '.sqlite';
    $renamed = @rename($target, $pre);
    if (!$renamed && !@copy($target, $pre)) return [false, '无法留存当前数据库（请检查目录写权限）'];
    $tmp = $target . '.restore-' . bin2hex(random_bytes(4));
    if (!@copy($source, $tmp)) {
        if ($renamed) @rename($pre, $target);
        return [false, '写入还原数据失败'];
    }
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        if ($renamed) @rename($pre, $target);
        return [false, '替换数据库文件失败'];
    }
    foreach (['-wal', '-shm'] as $suffix) {
        $f = $target . $suffix;
        if (is_file($f)) @unlink($f);
    }
    db_row_cache_clear();
    return [true, '已还原数据库；还原前数据留存为 ' . basename($pre)];
}

// JSON 数据快照还原：按表清空后逐行写回（单事务）。
// 兼容目标库与备份结构存在差异：目标库缺失的表跳过并提示；备份表非空但无同名列时整表跳过（不清空）；
// 备份中为空表时按快照语义清空目标表。
function backup_restore_json(string $source): array
{
    $data = json_decode((string)@file_get_contents($source), true);
    if (!is_array($data) || ($data['format'] ?? '') !== 'mono-backup' || !is_array($data['tables'] ?? null)) {
        return [false, '不是有效的 Mono 数据快照文件'];
    }
    $driver = db_driver();
    $restored = 0;
    $skipped = [];
    try {
        tx(function () use ($data, $driver, &$restored, &$skipped): void {
            foreach ($data['tables'] as $table => $rows) {
                $table = (string)$table;
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1 || !is_array($rows)) continue;
                $col_map = array_flip(backup_table_columns($table)); // 目标库中的列
                if (!$col_map) { $skipped[] = $table . '（目标库无此表）'; continue; }
                // 以首行样本计算可用列（备份 ∩ 目标库）。仅当备份表非空且无同名列时整表跳过，避免清空后无法写回。
                $use = [];
                $has_rows = false;
                foreach ($rows as $sample) {
                    if (is_array($sample) && $sample) {
                        $has_rows = true;
                        foreach ($sample as $c => $v) {
                            if (is_string($c) && isset($col_map[$c])) $use[$c] = true;
                        }
                        break;
                    }
                }
                if ($has_rows && !$use) { $skipped[] = $table . '（与目标库无同名列）'; continue; }
                $target = app_db_identifier($driver, $table);
                q('DELETE FROM ' . $target);
                foreach ($rows as $r) {
                    if (!is_array($r) || !$r) continue;
                    $names = [];
                    $vals = [];
                    foreach ($use as $c => $_) {
                        if (array_key_exists($c, $r)) { $names[] = app_db_identifier($driver, $c); $vals[] = $r[$c]; }
                    }
                    if (!$names) continue;
                    q('INSERT INTO ' . $target . '(' . implode(',', $names) . ') VALUES(' . sql_marks(count($names)) . ')', $vals);
                    $restored++;
                }
            }
        });
    } catch (\Throwable $e) {
        return [false, '数据还原失败：' . $e->getMessage()];
    }
    db_row_cache_clear();
    $msg = '数据快照已还原（写入 ' . $restored . ' 行）';
    if ($skipped) {
        $msg .= '；跳过 ' . count($skipped) . ' 张表：' . implode('、', array_slice($skipped, 0, 5)) . (count($skipped) > 5 ? ' 等' : '');
    }
    return [true, $msg];
}

function backup_restore_file(string $path): array
{
    return strtolower((string)pathinfo($path, PATHINFO_EXTENSION)) === 'json'
        ? backup_restore_json($path)
        : backup_restore_sqlite($path);
}

// --- 路由与 cron ---
function backup_download_route(array $plugin): void
{
    need_admin();
    $name = (string)($_GET['file'] ?? '');
    $path = backup_file_by_name($name);
    if ($path === '') err('备份文件不存在', 404);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . rawurlencode($name) . '"');
    header('Content-Length: ' . (string)filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

// cron 间隔（秒）：由配置决定，核心会强制最小 60 秒。
function backup_interval_seconds(array $plugin): int
{
    return ['hourly' => 3600, '6h' => 21600, 'daily' => 86400, 'weekly' => 604800][backup_config()['schedule_interval']] ?? 86400;
}

// cron 回调：异常只记录日志，绝不抛出（否则核心会停用整个插件）。
function backup_cron_task(array $plugin, array $task): void
{
    try {
        $cfg = backup_config();
        if (!$cfg['schedule_enabled']) return;
        backup_run($cfg);
    } catch (\Throwable $e) {
        backup_record_last(false, '', '计划任务异常：' . $e->getMessage());
        error_log('[Mono backup] cron: ' . $e->getMessage());
    }
}

// --- 后台页面 ---
function backup_admin(array $plugin): string
{
    if (is_post_request()) {
        $action = (string)($_POST['backup_action'] ?? '');
        $back = admin_url(['tab' => 'plugins', 'view' => 'backup']);
        $old = backup_config();

        if ($action === 'save') {
            $pass_post = (string)($_POST['smtp_pass'] ?? '');
            $dav_pass_post = (string)($_POST['webdav_pass'] ?? '');
            plugin_save_config('backup', [
                'schedule_enabled' => (int)($_POST['schedule_enabled'] ?? 0) === 1 ? 1 : 0,
                'schedule_interval' => (string)($_POST['schedule_interval'] ?? 'daily'),
                'keep_files' => (string)min(50, max(1, (int)($_POST['keep_files'] ?? 10))),
                'email_enabled' => (int)($_POST['email_enabled'] ?? 0) === 1 ? 1 : 0,
                'smtp_host' => post('smtp_host', 200),
                'smtp_port' => (string)min(65535, max(1, (int)($_POST['smtp_port'] ?? 465))),
                'smtp_secure' => (string)($_POST['smtp_secure'] ?? 'ssl'),
                'smtp_user' => post('smtp_user', 200),
                'smtp_pass' => $pass_post !== '' ? $pass_post : $old['smtp_pass'], // 留空表示保持
                'mail_from' => post('mail_from', 200),
                'mail_to' => post('mail_to', 200),
                'webdav_enabled' => (int)($_POST['webdav_enabled'] ?? 0) === 1 ? 1 : 0,
                'webdav_url' => post('webdav_url', 300),
                'webdav_user' => post('webdav_user', 200),
                'webdav_pass' => $dav_pass_post !== '' ? $dav_pass_post : $old['webdav_pass'],
            ]);
            set_flash('备份设置已保存');
            go($back);
        }

        if ($action === 'run_now') {
            @set_time_limit(600);
            $cfg = backup_config();
            [$ok, $summary] = backup_run($cfg);
            set_flash(($ok ? '备份完成：' : '备份存在失败：') . $summary, $ok ? 'ok' : 'error');
            go($back);
        }

        if ($action === 'delete') {
            $path = backup_file_by_name((string)($_POST['file'] ?? ''));
            if ($path === '') { set_flash('备份文件不存在', 'error'); go($back); }
            @unlink($path);
            set_flash('已删除 ' . basename($path));
            go($back);
        }

        if ($action === 'restore_local') {
            @set_time_limit(300);
            $path = backup_file_by_name((string)($_POST['file'] ?? ''));
            if ($path === '') { set_flash('备份文件不存在', 'error'); go($back); }
            [$ok, $msg] = backup_restore_file($path);
            set_flash(($ok ? '' : '还原失败：') . $msg, $ok ? 'ok' : 'error');
            go($back);
        }

        if ($action === 'restore_upload') {
            @set_time_limit(300);
            $f = $_FILES['backup_file'] ?? null;
            if (!$f || (int)$f['error'] === UPLOAD_ERR_NO_FILE) { set_flash('请选择要上传的备份文件', 'error'); go($back); }
            if ((int)$f['error'] !== UPLOAD_ERR_OK) { set_flash('上传失败（错误码 ' . (int)$f['error'] . '，可能超过服务器上传大小限制）', 'error'); go($back); }
            $tmp = (string)$f['tmp_name'];
            if (!is_uploaded_file($tmp)) { set_flash('非法的上传文件', 'error'); go($back); }
            $ext = strtolower((string)pathinfo((string)$f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['sqlite', 'db', 'json'], true)) { set_flash('仅支持 .sqlite / .db / .json 备份文件', 'error'); go($back); }
            [$ok, $msg] = backup_restore_file($tmp);
            set_flash(($ok ? '' : '还原失败：') . $msg, $ok ? 'ok' : 'error');
            go($back);
        }
    }

    $cfg = backup_config();
    $driver = db_driver();
    $last = backup_last_run();
    $files = backup_list_files();

    $html = '<p style="color:var(--text-muted);font-size:var(--font-size-sm);margin-top:0">'
        . '数据库驱动：<code>' . h($driver) . '</code>（' . ($driver === 'sqlite' ? '快照备份，支持一键还原' : 'JSON 数据快照，还原按表覆盖（缺表自动跳过）') . '）；'
        . '备份文件保存在 <code>app/data/backups/</code>。</p>';

    // 立即备份 + 上次运行状态。
    $html .= '<div class="btn-row" style="margin-bottom:10px">'
        . '<form method="post" style="display:inline">' . form_token()
        . '<input type="hidden" name="admin_action" value="noop"><input type="hidden" name="backup_action" value="run_now">'
        . '<button class="btn" type="submit">立即备份</button></form>'
        . '<span class="field-help" style="margin-left:8px">生成备份并按已启用的渠道推送</span>'
        . '</div>';
    if ($last) {
        $html .= '<div class="' . (!empty($last['ok']) ? 'backup-ok' : 'backup-fail') . '" style="font-size:var(--font-size-sm);margin-bottom:16px">'
            . '上次运行：' . h(date('Y-m-d H:i', (int)($last['time'] ?? 0))) . ' · '
            . (!empty($last['ok']) ? '成功' : '存在问题') . ' · ' . h((string)($last['file'] ?? ''))
            . '<div style="color:var(--text-muted)">' . h((string)($last['message'] ?? '')) . '</div></div>';
    }

    // 本地文件列表。
    $html .= '<table class="list" style="margin-bottom:20px"><thead><tr><th>文件</th><th>大小</th><th>时间</th><th class="actions">操作</th></tr></thead><tbody>';
    if (!$files) {
        $html .= '<tr><td colspan="4" style="color:var(--text-muted)">暂无备份文件，点上方「立即备份」生成一份。</td></tr>';
    }
    foreach ($files as $f) {
        $is_pre = str_starts_with($f['name'], 'pre-restore-');
        $html .= '<tr><td>' . h($f['name']) . ($is_pre ? ' <span style="color:var(--text-subtle);font-size:var(--font-size-xs)">（还原留底）</span>' : '') . '</td>'
            . '<td>' . h(backup_size_human($f['size'])) . '</td>'
            . '<td style="color:var(--text-subtle)">' . h(date('Y-m-d H:i', $f['time'])) . '</td>'
            . '<td class="actions"><div class="btn-row" style="justify-content:flex-end">'
            . '<a class="btn sm ghost" href="' . h(route_url('backup_download', ['file' => $f['name']])) . '">下载</a>'
            . '<form method="post" style="display:inline" data-confirm="确定用该文件还原吗？当前数据将被覆盖（还原前会自动留底）。">' . form_token()
            . '<input type="hidden" name="admin_action" value="noop"><input type="hidden" name="backup_action" value="restore_local">'
            . '<input type="hidden" name="file" value="' . h($f['name']) . '"><button class="btn sm" type="submit">还原</button></form>'
            . '<form method="post" style="display:inline" data-confirm="确定删除该备份文件？">' . form_token()
            . '<input type="hidden" name="admin_action" value="noop"><input type="hidden" name="backup_action" value="delete">'
            . '<input type="hidden" name="file" value="' . h($f['name']) . '"><button class="btn sm danger" type="submit">删除</button></form>'
            . '</div></td></tr>';
    }
    $html .= '</tbody></table>';

    // 设置表单（计划 + 两个渠道放一张表单，一次保存）。
    $html .= '<form method="post">' . form_token()
        . '<input type="hidden" name="admin_action" value="noop"><input type="hidden" name="backup_action" value="save">'
        . '<div style="font-weight:600;margin-bottom:8px">定时备份</div>'
        . checkbox('启用定时备份', 'schedule_enabled', $cfg['schedule_enabled'], '由外部 cron 每分钟调用任务入口，按下列间隔执行')
        . '<div class="form-grid-2">'
        . select_input('备份间隔', 'schedule_interval', $cfg['schedule_interval'], ['hourly' => '每小时', '6h' => '每 6 小时', 'daily' => '每天', 'weekly' => '每周'])
        . input('本地保留份数', 'keep_files', (string)$cfg['keep_files'], 'number', false, '1-50，超出自动删除最旧文件')
        . '</div>'

        . '<div style="font-weight:600;margin:18px 0 8px">渠道一：邮箱</div>'
        . checkbox('启用邮箱备份', 'email_enabled', $cfg['email_enabled'], '通过 SMTP 发送邮件，备份文件作为附件')
        . '<div class="form-grid-2">'
        . input('SMTP 服务器', 'smtp_host', $cfg['smtp_host'], 'text', false, '如 smtp.qq.com / smtp.163.com')
        . input('端口', 'smtp_port', (string)$cfg['smtp_port'], 'number', false, 'SSL 常用 465，STARTTLS 常用 587')
        . '</div>'
        . select_input('加密方式', 'smtp_secure', $cfg['smtp_secure'], ['ssl' => 'SSL（465）', 'starttls' => 'STARTTLS（587）', 'none' => '不加密'])
        . '<div class="form-grid-2">'
        . input('账号', 'smtp_user', $cfg['smtp_user'], 'text')
        . input('密码 / 授权码', 'smtp_pass', '', 'password', false, $cfg['smtp_pass'] !== '' ? '已保存，留空保持不变' : '')
        . '</div>'
        . '<div class="form-grid-2">'
        . input('发件人', 'mail_from', $cfg['mail_from'], 'text', false, '留空使用 SMTP 账号')
        . input('收件人', 'mail_to', $cfg['mail_to'], 'text', false, '多个用逗号分隔')
        . '</div>'

        . '<div style="font-weight:600;margin:18px 0 8px">渠道二：WebDAV</div>'
        . checkbox('启用 WebDAV 备份', 'webdav_enabled', $cfg['webdav_enabled'], '通过 HTTP PUT 上传到 WebDAV 目录（坚果云 / Nextcloud 等）')
        . input('目录 URL', 'webdav_url', $cfg['webdav_url'], 'text', false, '如 https://dav.jianguoyun.com/dav/我的坚果云/mono-backup')
        . '<div class="form-grid-2">'
        . input('账号', 'webdav_user', $cfg['webdav_user'], 'text')
        . input('密码', 'webdav_pass', '', 'password', false, $cfg['webdav_pass'] !== '' ? '已保存，留空保持不变' : '')
        . '</div>'

        . '<button class="btn" type="submit" style="margin-top:16px">保存设置</button></form>';

    // 上传还原。
    $html .= '<form method="post" enctype="multipart/form-data" style="margin-top:20px">' . form_token()
        . '<input type="hidden" name="admin_action" value="noop"><input type="hidden" name="backup_action" value="restore_upload">'
        . '<div style="font-weight:600;margin-bottom:8px">上传还原</div>'
        . '<label class="grid">' . form_field_caption('选择备份文件', '支持 .sqlite / .db（SQLite 快照）或 .json（数据快照）；受服务器上传大小限制')
        . '<input type="file" name="backup_file" accept=".sqlite,.db,.json"></label>'
        . '<div class="btn-row" style="margin-top:10px">'
        . '<button class="btn danger" type="submit" data-confirm="确定上传并还原吗？当前数据将被覆盖（还原前会自动留底）。">上传并还原</button>'
        . '</div></form>';

    // cron 提示（HTTP 地址自动附带访问令牌；本地命令无需令牌）。
    $cron_cli = cron_cli_command();
    $cron_url = cron_secret_url();
    $html .= '<div class="note" style="margin-top:20px">外部定时任务（crontab）示例（建议每分钟调用一次，插件按设定间隔触发备份；实际触发频率取决于调用频率）：<br>'
        . '<code>* * * * * ' . h($cron_cli) . '</code><br>'
        . '或：<code>* * * * * curl -s "' . h($cron_url) . '" &gt;/dev/null</code>（地址含安全令牌，必须整体复制；不带令牌的旧地址会返回 403）</div>';

    return $html;
}

function backup_css(): string
{
    return <<<'CSS'
.backup-ok{color:var(--success)}
.backup-fail{color:var(--danger)}
CSS;
}

return [
    'id' => 'backup',
    'name' => '备份 / 还原',
    'version' => '1.0.2',
    'description' => '一键备份数据库（SQLite / MySQL / PostgreSQL）并支持下载 / 还原；可开启定时备份，备份文件可同时推送到邮箱或 WebDAV。',
    'author' => 'Mono',
    'assets' => ['css' => 'backup_css'],
    'routes' => ['backup_download' => 'backup_download_route'],
    'admin_tabs' => ['backup' => 'backup_admin'],
    'cron' => ['scheduled' => ['callback' => 'backup_cron_task', 'interval' => 'backup_interval_seconds']],
    'install' => 'backup_install',
    'uninstall' => 'backup_uninstall',
];
