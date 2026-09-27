<?php
if (!defined('APP_ROOT')) exit;

/**
 * 图床插件（imgbed）。
 *
 * 把写作图片上传到外部对象存储（零第三方依赖，curl / stream 双通道）：
 * - 四种存储源单选：阿里云 OSS / 腾讯云 COS / Cloudflare R2 / WebDAV。
 * - 写文章页工具栏注入「上传图片」按钮：选图 → AJAX 上传 → 以 Markdown 自动插入光标处。
 * - 上传记录存 plugin_imgbed_files；后台「图床」标签分页浏览（复制链接 / 删除，
 *   删除时尽力同步删除远端对象）；「插件 → 图床」配置页管理凭据与图片处理开关。
 * - 图片处理：去 EXIF 默认开（JPEG 按拍摄方向纠正并剥离元数据，GIF 跳过防丢动画）；
 *   自动压缩默认关（超最长边等比缩放，JPEG / WebP 按质量重编码）。
 *
 * 安全：上传路由 need_admin + POST + CSRF；≤10MB 与 getimagesize 白名单校验；
 * 对象键服务端生成（{路径前缀}/YYYY/MM/{16hex}.{ext}）；凭据仅存服务端、不回显；
 * 所有回调内 try/catch，避免异常导致插件被核心自动停用。
 */

// --- 配置 ---
function imgbed_sources(): array
{
    return ['oss' => '阿里云 OSS', 'cos' => '腾讯云 COS', 'r2' => 'Cloudflare R2', 'webdav' => 'WebDAV'];
}

// 归一化配置（旧配置缺字段不报错；各源凭据全量保留，切换源不丢数据）。
function imgbed_parse_config(array $raw): array
{
    $source = (string)($raw['source'] ?? 'oss');
    if (!isset(imgbed_sources()[$source])) $source = 'oss';
    $sub = static function (string $name, array $keys) use ($raw): array {
        $src = is_array($raw[$name] ?? null) ? $raw[$name] : [];
        $out = [];
        foreach ($keys as $k) $out[$k] = trim((string)($src[$k] ?? ''));
        return $out;
    };
    $prefix = trim((string)($raw['path_prefix'] ?? 'blog'));
    $prefix = trim((string)(preg_replace('#[^A-Za-z0-9/_-]#', '', $prefix) ?? ''), '/');
    $url_prefix = rtrim(trim((string)($raw['url_prefix'] ?? '')), '/');
    // 访问域名漏填协议时按 https 补全（否则浏览器会把链接当相对路径解析）。
    if ($url_prefix !== '' && !preg_match('#^https?://#i', $url_prefix)) $url_prefix = 'https://' . $url_prefix;
    return [
        'source' => $source,
        'oss' => $sub('oss', ['endpoint', 'bucket', 'access_key_id', 'access_key_secret']),
        'cos' => $sub('cos', ['region', 'bucket', 'secret_id', 'secret_key']),
        'r2' => $sub('r2', ['account_id', 'bucket', 'access_key_id', 'access_key_secret']),
        'webdav' => $sub('webdav', ['base_url', 'username', 'password']),
        'url_prefix' => $url_prefix,
        'path_prefix' => $prefix !== '' ? $prefix : 'blog',
        'process_exif' => (int)($raw['process_exif'] ?? 1) === 1,
        'process_compress' => (int)($raw['process_compress'] ?? 0) === 1,
        'compress_quality' => min(95, max(50, (int)($raw['compress_quality'] ?? 82))),
        'compress_max_width' => min(6000, max(600, (int)($raw['compress_max_width'] ?? 1920))),
    ];
}

function imgbed_config(): array
{
    return imgbed_parse_config(plugin_config('imgbed', []));
}

// 当前选中源的必填项是否齐全。
function imgbed_ready(array $cfg): bool
{
    $s = $cfg[$cfg['source']];
    return match ($cfg['source']) {
        'oss' => $s['endpoint'] !== '' && $s['bucket'] !== '' && $s['access_key_id'] !== '' && $s['access_key_secret'] !== '',
        'cos' => $s['region'] !== '' && $s['bucket'] !== '' && $s['secret_id'] !== '' && $s['secret_key'] !== '',
        'r2' => $s['account_id'] !== '' && $s['bucket'] !== '' && $s['access_key_id'] !== '' && $s['access_key_secret'] !== '',
        'webdav' => (bool)preg_match('#^https?://#i', $s['base_url']),
        default => false,
    };
}

// endpoint 允许带 http(s):// 前缀（内网地址），拆出 [scheme, host]。
function imgbed_host_split(string $endpoint): array
{
    $endpoint = trim($endpoint);
    $scheme = 'https';
    if (preg_match('#^(https?)://#i', $endpoint, $m)) {
        $scheme = strtolower($m[1]);
        $endpoint = substr($endpoint, strlen($m[0]));
    }
    return [$scheme, rtrim($endpoint, '/')];
}

function imgbed_mimes(): array
{
    return ['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
}

// --- HTTP 层（curl 优先，stream 兜底；SSL 校验开启，不跟随重定向避免签名失效）---
function imgbed_http(string $method, string $url, array $headers, ?string $body, int $timeout = 120): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = (string)curl_error($ch);
        curl_close($ch);
        if ($resp === false || $errno !== 0) return [false, 0, '网络请求失败：' . ($error !== '' ? $error : '未知错误')];
        return [true, $status, (string)$resp];
    }

    $ctx = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => (string)$body,
        'timeout' => $timeout,
        'ignore_errors' => true,
        'follow_location' => 0,
    ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) return [false, 0, '网络请求失败：无法连接目标地址'];
    $status = 0;
    foreach ((array)($http_response_header ?? []) as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$line, $m)) $status = (int)$m[1];
    }
    return [true, $status, (string)$resp];
}

/**
 * 为当前存储源构造一次对象请求（含鉴权头）。$bytes 为 null 表示无请求体（DELETE）。
 * 返回 [url, headers, error]。
 */
function imgbed_signed_request(array $cfg, string $method, string $key, string $mime, ?string $bytes): array
{
    switch ($cfg['source']) {
        case 'oss':
            // 阿里云 OSS V1 头签名（HMAC-SHA1）；PUT 时以 x-oss-object-acl 让对象独立公开可读。
            $o = $cfg['oss'];
            [$scheme, $host] = imgbed_host_split($o['endpoint']);
            $url = $scheme . '://' . $o['bucket'] . '.' . $host . '/' . $key;
            $date = gmdate('D, d M Y H:i:s \G\M\T');
            $headers = ['Date: ' . $date];
            $canonical = '';
            $content_type = '';
            if ($method === 'PUT') {
                $content_type = $mime;
                $canonical = "x-oss-object-acl:public-read\n";
                $headers[] = 'Content-Type: ' . $mime;
                $headers[] = 'x-oss-object-acl: public-read';
            }
            $string_to_sign = $method . "\n\n" . $content_type . "\n" . $date . "\n" . $canonical . '/' . $o['bucket'] . '/' . $key;
            $headers[] = 'Authorization: OSS ' . $o['access_key_id'] . ':' . base64_encode(hash_hmac('sha1', $string_to_sign, $o['access_key_secret'], true));
            return [$url, $headers, ''];

        case 'cos':
            // 腾讯云 COS：q-sign-algorithm=sha1（SignKey / StringToSign 派生 Authorization）。
            $c = $cfg['cos'];
            $host = $c['bucket'] . '.cos.' . $c['region'] . '.myqcloud.com';
            $url = 'https://' . $host . '/' . $key;
            $now = time();
            $key_time = $now . ';' . ($now + 3600);
            $sign_key = hash_hmac('sha1', $key_time, $c['secret_key']);
            $http_string = strtolower($method) . "\n/" . $key . "\n\nhost=" . $host . "\n";
            $string_to_sign = "sha1\n" . $key_time . "\n" . sha1($http_string) . "\n";
            $headers = [
                'Authorization: q-sign-algorithm=sha1&q-ak=' . $c['secret_id']
                . '&q-sign-time=' . $key_time . '&q-key-time=' . $key_time
                . '&q-header-list=host&q-url-param-list=&q-signature=' . hash_hmac('sha1', $string_to_sign, $sign_key),
            ];
            if ($method === 'PUT') {
                array_unshift($headers, 'Content-Type: ' . $mime);
                $headers[] = 'x-cos-acl: public-read';
            }
            return [$url, $headers, ''];

        case 'r2':
            // Cloudflare R2：AWS SigV4（region 固定 auto，service 固定 s3；R2 不支持对象 ACL）。
            $r = $cfg['r2'];
            $host = $r['account_id'] . '.r2.cloudflarestorage.com';
            $uri = '/' . $r['bucket'] . '/' . $key;
            $url = 'https://' . $host . $uri;
            $amz_date = gmdate('Ymd\THis\Z');
            $datestamp = gmdate('Ymd');
            $payload_hash = hash('sha256', (string)$bytes);
            $canonical_headers = "host:$host\nx-amz-content-sha256:$payload_hash\nx-amz-date:$amz_date\n";
            $signed = 'host;x-amz-content-sha256;x-amz-date';
            $canonical_request = $method . "\n" . $uri . "\n\n" . $canonical_headers . "\n" . $signed . "\n" . $payload_hash;
            $scope = $datestamp . '/auto/s3/aws4_request';
            $sts = "AWS4-HMAC-SHA256\n" . $amz_date . "\n" . $scope . "\n" . hash('sha256', $canonical_request);
            $k = hash_hmac('sha256', $datestamp, 'AWS4' . $r['access_key_secret'], true);
            $k = hash_hmac('sha256', 'auto', $k, true);
            $k = hash_hmac('sha256', 's3', $k, true);
            $k = hash_hmac('sha256', 'aws4_request', $k, true);
            $headers = [
                'x-amz-content-sha256: ' . $payload_hash,
                'x-amz-date: ' . $amz_date,
                'Authorization: AWS4-HMAC-SHA256 Credential=' . $r['access_key_id'] . '/' . $scope
                . ', SignedHeaders=' . $signed . ', Signature=' . hash_hmac('sha256', $sts, $k),
            ];
            if ($method === 'PUT') array_unshift($headers, 'Content-Type: ' . $mime);
            return [$url, $headers, ''];

        case 'webdav':
            $w = $cfg['webdav'];
            $base = rtrim($w['base_url'], '/');
            if (!preg_match('#^https?://#i', $base)) return ['', [], 'WebDAV 地址需以 http(s):// 开头'];
            $headers = [];
            if ($w['username'] !== '' || $w['password'] !== '') {
                $headers[] = 'Authorization: Basic ' . base64_encode($w['username'] . ':' . $w['password']);
            }
            if ($method === 'PUT') $headers[] = 'Content-Type: ' . $mime;
            return [$base . '/' . ltrim($key, '/'), $headers, ''];
    }
    return ['', [], '未知的存储源'];
}

// 截断远端响应作为错误摘要（避免把大段 HTML 错误页带进提示）。
function imgbed_err_snip(string $body): string
{
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');
    return $text !== '' ? '：' . cut($text, 160) : '';
}

// 统一的远端读写出口：PUT 上传 / DELETE 删除。返回 [ok, err]。
function imgbed_store(array $cfg, string $method, string $key, string $mime, ?string $bytes): array
{
    [$url, $headers, $error] = imgbed_signed_request($cfg, $method, $key, $mime, $bytes);
    if ($error !== '') return [false, $error];
    [$ok, $status, $body] = imgbed_http($method, $url, $headers, $bytes, $method === 'PUT' ? 120 : 30);
    // WebDAV 父目录不存在时服务器返回 409：逐级 MKCOL 后重试一次。
    if ($method === 'PUT' && $ok && $status === 409 && $cfg['source'] === 'webdav') {
        imgbed_webdav_mkdir($cfg, $key);
        [$ok, $status, $body] = imgbed_http($method, $url, $headers, $bytes, 120);
    }
    if (!$ok) return [false, $body];
    if ($status >= 200 && $status < 300) return [true, ''];
    return [false, 'HTTP ' . $status . imgbed_err_snip($body)];
}

// WebDAV：逐级创建对象键中的目录（已存在返回 405，一并忽略）。
function imgbed_webdav_mkdir(array $cfg, string $key): void
{
    $parts = explode('/', trim($key, '/'));
    array_pop($parts);
    if (!$parts) return;
    $headers = [];
    $w = $cfg['webdav'];
    if ($w['username'] !== '' || $w['password'] !== '') {
        $headers[] = 'Authorization: Basic ' . base64_encode($w['username'] . ':' . $w['password']);
    }
    $path = rtrim($w['base_url'], '/');
    foreach ($parts as $seg) {
        $path .= '/' . rawurlencode($seg);
        imgbed_http('MKCOL', $path, $headers, null, 30);
    }
}

// --- 对象键与访问地址 ---
function imgbed_build_key(array $cfg, string $ext): string
{
    return $cfg['path_prefix'] . '/' . date('Y') . '/' . date('m') . '/' . bin2hex(random_bytes(8)) . '.' . $ext;
}

function imgbed_public_url(array $cfg, string $key): string
{
    if ($cfg['url_prefix'] !== '') return $cfg['url_prefix'] . '/' . $key;
    switch ($cfg['source']) {
        case 'oss':
            [$scheme, $host] = imgbed_host_split($cfg['oss']['endpoint']);
            return $scheme . '://' . $cfg['oss']['bucket'] . '.' . $host . '/' . $key;
        case 'cos':
            return 'https://' . $cfg['cos']['bucket'] . '.cos.' . $cfg['cos']['region'] . '.myqcloud.com/' . $key;
        case 'r2':
            return 'https://' . $cfg['r2']['account_id'] . '.r2.cloudflarestorage.com/' . $cfg['r2']['bucket'] . '/' . $key;
        case 'webdav':
            return rtrim($cfg['webdav']['base_url'], '/') . '/' . $key;
    }
    return '';
}

function imgbed_size_text(int $bytes): string
{
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024) . ' KB';
    return $bytes . ' B';
}

// 原文件名清洗：去扩展名与 Markdown 破坏字符，作为插入正文的 alt 文本。
function imgbed_clean_name(string $name): string
{
    $name = preg_replace('#\.[A-Za-z0-9]{1,5}$#', '', $name) ?? $name;
    $name = str_replace(['[', ']', '(', ')', '!', '*', '`', '<', '>', '|', "\n", "\r"], '', $name);
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    return $name !== '' ? cut($name, 60) : 'image';
}

// --- 图片处理（GD；返回 [bytes, ext, note]，note 仅降级路径非空）---
function imgbed_process(string $tmp_file, string $ext, array $cfg): array
{
    $bytes = (string)@file_get_contents($tmp_file);
    if (!$cfg['process_exif'] && !$cfg['process_compress']) return [$bytes, $ext, ''];
    if ($ext === 'gif') return [$bytes, $ext, ''];   // GIF 跳过重编码，避免丢失动画
    if (!function_exists('imagecreatefromstring')) return [$bytes, $ext, '服务器未启用 GD 扩展，图片已按原图上传'];
    $im = @imagecreatefromstring($bytes);
    if ($im === false) return [$bytes, $ext, '图片解析失败，已按原图上传'];

    // 按 EXIF Orientation 纠正拍摄方向（仅 JPEG；重编码时元数据自然剥离）。
    if ($cfg['process_exif'] && in_array($ext, ['jpg', 'jpeg'], true)) {
        $orientation = 0;
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($tmp_file);
            $orientation = (int)($exif['Orientation'] ?? 0);
        }
        $im = imgbed_apply_orientation($im, $orientation);
    }

    // 自动压缩：超过最长边时等比缩放（truecolor + alpha 通道保持透明）。
    if ($cfg['process_compress']) {
        $w = imagesx($im);
        $h = imagesy($im);
        $max = $cfg['compress_max_width'];
        if ($w > $max || $h > $max) {
            $scale = $max / max($w, $h);
            $nw = max(1, (int)floor($w * $scale));
            $nh = max(1, (int)floor($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($im);
            $im = $dst;
        }
    }

    // 重编码输出：压缩开启时按配置质量；仅去元数据时用高保真质量。
    $quality = $cfg['process_compress'] ? $cfg['compress_quality'] : 92;
    ob_start();
    match ($ext) {
        'jpg', 'jpeg' => imagejpeg($im, null, $quality),
        'png' => imagepng($im, null, 6),
        'webp' => imagewebp($im, null, $quality),
        default => null,
    };
    $out = (string)ob_get_clean();
    imagedestroy($im);
    if (strlen($out) < 64) return [$bytes, $ext, '图片重编码失败，已按原图上传'];
    return [$out, $ext, ''];
}

// EXIF Orientation 旋转/翻转（2/4 为镜像，5/7 为镜像 + 旋转的常见校正实践）。
function imgbed_apply_orientation(\GdImage $im, int $orientation): \GdImage
{
    $fn = match ($orientation) {
        2 => static fn(\GdImage $i): \GdImage => imgbed_flip($i, IMG_FLIP_HORIZONTAL),
        3 => static fn(\GdImage $i): \GdImage => imgbed_rotate($i, 180),
        4 => static fn(\GdImage $i): \GdImage => imgbed_flip($i, IMG_FLIP_VERTICAL),
        5 => static fn(\GdImage $i): \GdImage => imgbed_flip(imgbed_rotate($i, -90), IMG_FLIP_HORIZONTAL),
        6 => static fn(\GdImage $i): \GdImage => imgbed_rotate($i, -90),
        7 => static fn(\GdImage $i): \GdImage => imgbed_flip(imgbed_rotate($i, 90), IMG_FLIP_HORIZONTAL),
        8 => static fn(\GdImage $i): \GdImage => imgbed_rotate($i, 90),
        default => null,
    };
    return $fn === null ? $im : $fn($im);
}

function imgbed_rotate(\GdImage $im, float $angle): \GdImage
{
    $r = imagerotate($im, $angle, 0);
    if (!$r instanceof \GdImage) return $im;
    if ($r !== $im) imagedestroy($im);
    return $r;
}

function imgbed_flip(\GdImage $im, int $mode): \GdImage
{
    imageflip($im, $mode);
    return $im;
}

// 测试连接的探测图片：1×1 PNG（GD 优先生成，无 GD 用内置常量；内容不参与校验）。
function imgbed_probe_png(): string
{
    if (function_exists('imagecreatetruecolor')) {
        $im = imagecreatetruecolor(1, 1);
        ob_start();
        imagepng($im);
        $png = (string)ob_get_clean();
        imagedestroy($im);
        if ($png !== '') return $png;
    }
    return (string)base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
}

// 连通性测试：上传 1×1 PNG 探测对象，成功后尽力删除。返回 [ok, message]。
function imgbed_test(array $cfg): array
{
    if (!imgbed_ready($cfg)) return [false, '请先完善当前存储源的必填凭据'];
    $key = $cfg['path_prefix'] . '/.probe-' . bin2hex(random_bytes(4)) . '.png';
    [$ok, $err] = imgbed_store($cfg, 'PUT', $key, 'image/png', imgbed_probe_png());
    if (!$ok) return [false, $err];
    [$dok, $derr] = imgbed_store($cfg, 'DELETE', $key, '', null);
    $url = imgbed_public_url($cfg, $key);
    return [true, '上传成功（' . $url . '），探测对象' . ($dok ? '已清理' : '清理失败：' . $derr)];
}

// --- 上传路由（写文章页 AJAX，need_admin + CSRF 由核心统一校验）---
function imgbed_upload_route(array $plugin): void
{
    need_admin();
    require_post();
    try {
        $cfg = imgbed_config();
        if (!imgbed_ready($cfg)) json_response(['ok' => 0, 'message' => '图床尚未配置完成，请到「后台 → 图床 → 设置」填写凭据']);
        $f = $_FILES['file'] ?? null;
        if (!is_array($f) || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) json_response(['ok' => 0, 'message' => '未收到上传文件']);
        if ((int)$f['error'] !== UPLOAD_ERR_OK) json_response(['ok' => 0, 'message' => '上传失败（错误码 ' . (int)$f['error'] . '）']);
        if ((int)($f['size'] ?? 0) > 10 * 1024 * 1024) json_response(['ok' => 0, 'message' => '图片不能超过 10MB']);
        $tmp = (string)($f['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) json_response(['ok' => 0, 'message' => '非法的上传文件']);
        $info = @getimagesize($tmp);
        $ext = $info === false ? false : array_search((string)$info['mime'], imgbed_mimes(), true);
        if ($ext === false) json_response(['ok' => 0, 'message' => '仅支持 PNG / JPG / GIF / WebP 格式图片']);

        $orig_name = imgbed_clean_name((string)($f['name'] ?? 'image'));
        [$bytes, $ext, $note] = imgbed_process($tmp, (string)$ext, $cfg);
        $mime = imgbed_mimes()[$ext] ?? 'application/octet-stream';
        $key = imgbed_build_key($cfg, $ext);
        [$ok, $err] = imgbed_store($cfg, 'PUT', $key, $mime, $bytes);
        if (!$ok) json_response(['ok' => 0, 'message' => '上传图床失败' . ($err !== '' ? '：' . $err : '')]);

        $url = imgbed_public_url($cfg, $key);
        // R2 默认（S3 API）端点不允许匿名读取，未配置访问域名时提前给出提示。
        if ($note === '' && $cfg['source'] === 'r2' && $cfg['url_prefix'] === '') {
            $note = '未配置访问域名：R2 默认端点不支持匿名访问，图片链接可能无法显示，请到「后台 → 图床 → 设置」填写自定义域名 / r2.dev 域名';
        }
        q('INSERT INTO plugin_imgbed_files(user_id,name,store_key,url,mime,source,size,created_at) VALUES(?,?,?,?,?,?,?,?)',
            [uid(), $orig_name, $key, $url, $mime, $cfg['source'], strlen($bytes), now()]);
        json_response(['ok' => 1, 'url' => $url, 'name' => $orig_name, 'note' => $note]);
    } catch (\Throwable $e) {
        error_log('[Mono imgbed] upload: ' . $e->getMessage());
        json_response(['ok' => 0, 'message' => '上传失败，请查看站点错误日志']);
    }
}

// --- 写文章页注入：工具栏「上传图片」按钮 + 隐藏文件选择框 ---
function imgbed_write_inject(string $value, array $ctx): string
{
    try {
        $title = (string)($ctx['title'] ?? '');
        if ($title !== '写文章' && $title !== '编辑文章') return $value;
        if (!str_contains($value, '<span class="md-spacer"></span>')) return $value;
        if (str_contains($value, 'data-imgbed-open')) return $value;   // 幂等保护
        $btn = '<button type="button" class="md-btn imgbed-open" data-imgbed-open="1" title="上传图片到图床并插入正文">'
            . '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>'
            . '<span class="imgbed-label">上传图片</span></button>';
        $value = str_replace('<span class="md-spacer"></span>', $btn . '<span class="md-spacer"></span>', $value);
        $helper = '<input type="file" class="imgbed-file" accept="image/png,image/jpeg,image/gif,image/webp" hidden'
            . ' data-url="' . h(route_url('imgbed_upload')) . '" data-csrf="' . h(csrf_token()) . '">';
        return str_replace('<div class="btn-row write-actions">', $helper . '<div class="btn-row write-actions">', $value);
    } catch (\Throwable $e) {
        error_log('[Mono imgbed] inject: ' . $e->getMessage());
        return $value;
    }
}

// --- 后台：常驻「图床」标签 + 配置子页 ---
function imgbed_admin_tabs(array $tabs, array $ctx): array
{
    $tabs['imgbed'] = '图床';
    return $tabs;
}

// POST 表单 → 配置数组（密码类字段留空表示保持原值）。
function imgbed_post_config(array $old): array
{
    $flat = static fn(string $field): string => trim((string)($_POST[$field] ?? ''));
    $keep = static function (string $field, string $old_val): string {
        $v = trim((string)($_POST[$field] ?? ''));
        return $v !== '' ? $v : $old_val;
    };
    $old_sub = static fn(string $name): array => is_array($old[$name] ?? null) ? $old[$name] : [];
    $source = $flat('source');
    $raw = [
        'source' => isset(imgbed_sources()[$source]) ? $source : 'oss',
        'oss' => [
            'endpoint' => $flat('oss_endpoint'),
            'bucket' => $flat('oss_bucket'),
            'access_key_id' => $flat('oss_access_key_id'),
            'access_key_secret' => $keep('oss_access_key_secret', (string)($old_sub('oss')['access_key_secret'] ?? '')),
        ],
        'cos' => [
            'region' => $flat('cos_region'),
            'bucket' => $flat('cos_bucket'),
            'secret_id' => $flat('cos_secret_id'),
            'secret_key' => $keep('cos_secret_key', (string)($old_sub('cos')['secret_key'] ?? '')),
        ],
        'r2' => [
            'account_id' => $flat('r2_account_id'),
            'bucket' => $flat('r2_bucket'),
            'access_key_id' => $flat('r2_access_key_id'),
            'access_key_secret' => $keep('r2_access_key_secret', (string)($old_sub('r2')['access_key_secret'] ?? '')),
        ],
        'webdav' => [
            'base_url' => $flat('webdav_base_url'),
            'username' => $flat('webdav_username'),
            'password' => $keep('webdav_password', (string)($old_sub('webdav')['password'] ?? '')),
        ],
        'url_prefix' => $flat('url_prefix'),
        'path_prefix' => $flat('path_prefix'),
        'process_exif' => (int)($_POST['process_exif'] ?? 0) === 1 ? 1 : 0,
        'process_compress' => (int)($_POST['process_compress'] ?? 0) === 1 ? 1 : 0,
        'compress_quality' => min(95, max(50, (int)($_POST['compress_quality'] ?? 82))),
        'compress_max_width' => min(6000, max(600, (int)($_POST['compress_max_width'] ?? 1920))),
    ];
    // 统一走归一化清洗（path_prefix 白名单、url_prefix 去尾斜杠等）。
    return imgbed_parse_config($raw);
}

function imgbed_admin(array $plugin): string
{
    try {
        $from_tab = (string)($_GET['tab'] ?? '') === 'imgbed';
        $back = $from_tab ? admin_url(['tab' => 'imgbed']) : admin_url(['tab' => 'plugins', 'view' => 'imgbed']);
        if (is_post_request()) {
            $action = (string)($_POST['imgbed_action'] ?? '');
            if ($action === 'save' || $action === 'test') {
                @set_time_limit(120);
                $save = imgbed_post_config(plugin_config('imgbed', []));
                plugin_save_config('imgbed', $save);
                if ($action === 'test') {
                    [$ok, $msg] = imgbed_test(imgbed_config());
                    set_flash(($ok ? '设置已保存；连通测试通过：' : '设置已保存；连通测试失败：') . $msg, $ok ? 'ok' : 'error');
                } else {
                    set_flash('图床设置已保存');
                }
                go($back);
            }
            if ($action === 'delete' && $from_tab) {
                $id = (int)($_POST['id'] ?? 0);
                $file = one('SELECT * FROM plugin_imgbed_files WHERE id=?', [$id]);
                if ($file) {
                    // 用记录中的存储源删除（配置切换过源也能清理旧对象）。
                    $cfg = imgbed_config();
                    if (isset(imgbed_sources()[(string)$file['source']])) $cfg['source'] = (string)$file['source'];
                    [$ok, $err] = imgbed_store($cfg, 'DELETE', (string)$file['store_key'], (string)$file['mime'], null);
                    q('DELETE FROM plugin_imgbed_files WHERE id=?', [$id]);
                    set_flash($ok ? '图片已删除' : '本地记录已删除；远端对象删除失败（' . $err . '），可手动到图床控制台清理', $ok ? 'ok' : 'error');
                }
                go($back);
            }
        }
        return $from_tab ? imgbed_admin_list() : imgbed_admin_form(imgbed_config());
    } catch (\Throwable $e) {
        error_log('[Mono imgbed] admin: ' . $e->getMessage());
        return '<p style="color:var(--danger)">图床后台渲染失败：' . h($e->getMessage()) . '</p>';
    }
}

// 「图床」标签：已上传图片分页列表（20 条/页）。
function imgbed_admin_list(): string
{
    $page = current_page();
    $per = 20;
    $total = (int)val('SELECT COUNT(*) FROM plugin_imgbed_files');
    $rows = all('SELECT * FROM plugin_imgbed_files ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));

    $html = '<div class="btn-row" style="justify-content:flex-end;margin-bottom:10px">'
        . '<a class="btn sm ghost" href="' . h(admin_url(['tab' => 'plugins', 'view' => 'imgbed'])) . '">图床设置</a></div>';
    if (!$rows) {
        return $html . '<p style="color:var(--text-muted)">还没有上传记录。在写文章页点工具栏「上传图片」即可把图片传到图床。</p>';
    }
    $html .= '<table class="list"><thead><tr><th>图片</th><th>文件名</th><th>大小</th><th>来源</th><th>时间</th><th class="actions">操作</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $html .= '<tr>'
            . '<td><a href="' . h((string)$r['url']) . '" target="_blank" rel="noopener"><img class="imgbed-thumb" src="' . h((string)$r['url']) . '" alt="" loading="lazy"></a></td>'
            . '<td>' . h(cut((string)$r['name'], 40)) . '</td>'
            . '<td style="color:var(--text-subtle)">' . h(imgbed_size_text((int)$r['size'])) . '</td>'
            . '<td><span class="badge">' . h(strtoupper((string)$r['source'])) . '</span></td>'
            . '<td style="color:var(--text-subtle)">' . h(date('Y-m-d H:i', (int)$r['created_at'])) . '</td>'
            . '<td class="actions"><div class="btn-row" style="justify-content:flex-end">'
            . '<button type="button" class="btn sm ghost" data-imgbed-copy="' . h((string)$r['url']) . '">复制链接</button>'
            . post_action_form(admin_url(['tab' => 'imgbed']), '删除', ['admin_action' => 'noop', 'imgbed_action' => 'delete', 'id' => (int)$r['id']], 'btn sm danger', '删除这张图片？将同时尽力删除远端对象（失败时仅移除本地记录）。')
            . '</div></td></tr>';
    }
    $html .= '</tbody></table>';
    return $html . paginate($total, $page, $per, fn(int $p): string => admin_url(['tab' => 'imgbed', 'page' => $p]));
}

// 配置子页：存储源凭据 + 图片处理开关。
function imgbed_admin_form(array $cfg): string
{
    $secret_help = static fn(string $val, string $empty): string => $val !== '' ? '已保存；留空保持不变' : $empty;

    $html = '<form method="post">' . form_token()
        . '<input type="hidden" name="admin_action" value="noop">'
        . select_input('存储源', 'source', $cfg['source'], imgbed_sources(), '只有当前选中源的凭据会被使用；各源凭据独立保存，来回切换不丢失（下方仅显示当前源的配置项）');

    // 凭据分组：只展示当前选中源；其余块 hidden 但仍随表单提交（切换源不丢数据），切换 select 由 JS 即时显隐。
    $blocks = [
        'oss' => '<div class="imgbed-src-title">阿里云 OSS</div><div class="form-grid-2">'
            . input('Endpoint', 'oss_endpoint', $cfg['oss']['endpoint'], 'text', false, '如 oss-cn-hangzhou.aliyuncs.com（可带 https:// 前缀）')
            . input('Bucket', 'oss_bucket', $cfg['oss']['bucket'])
            . input('AccessKey ID', 'oss_access_key_id', $cfg['oss']['access_key_id'])
            . input('AccessKey Secret', 'oss_access_key_secret', '', 'password', false, $secret_help($cfg['oss']['access_key_secret'], ''))
            . '</div>',
        'cos' => '<div class="imgbed-src-title">腾讯云 COS</div><div class="form-grid-2">'
            . input('Region', 'cos_region', $cfg['cos']['region'], 'text', false, '如 ap-guangzhou')
            . input('Bucket', 'cos_bucket', $cfg['cos']['bucket'], 'text', false, '含 APPID，如 name-1250000000')
            . input('SecretId', 'cos_secret_id', $cfg['cos']['secret_id'])
            . input('SecretKey', 'cos_secret_key', '', 'password', false, $secret_help($cfg['cos']['secret_key'], ''))
            . '</div>',
        'r2' => '<div class="imgbed-src-title">Cloudflare R2</div><div class="form-grid-2">'
            . input('Account ID', 'r2_account_id', $cfg['r2']['account_id'])
            . input('Bucket', 'r2_bucket', $cfg['r2']['bucket'])
            . input('AccessKey ID', 'r2_access_key_id', $cfg['r2']['access_key_id'])
            . input('AccessKey Secret', 'r2_access_key_secret', '', 'password', false, $secret_help($cfg['r2']['access_key_secret'], ''))
            . '</div>',
        'webdav' => '<div class="imgbed-src-title">WebDAV</div><div class="form-grid-2">'
            . input('地址', 'webdav_base_url', $cfg['webdav']['base_url'], 'text', false, '如 https://dav.example.com/blog；子目录不存在时自动创建')
            . input('用户名', 'webdav_username', $cfg['webdav']['username'])
            . input('密码', 'webdav_password', '', 'password', false, $secret_help($cfg['webdav']['password'], ''))
            . '</div>',
    ];
    foreach ($blocks as $sid => $block) {
        $html .= '<div class="imgbed-src-block" data-imgbed-source="' . $sid . '"' . ($sid === $cfg['source'] ? '' : ' hidden') . '>' . $block . '</div>';
    }

    $html .= '<div class="imgbed-src-title">通用</div><div class="form-grid-2">'
        . input('访问域名', 'url_prefix', $cfg['url_prefix'], 'text', false, '可选。自定义域名 / CDN，如 https://cdn.example.com；留空使用存储默认域名（R2 需自行配置公共访问）')
        . input('路径前缀', 'path_prefix', $cfg['path_prefix'], 'text', false, '对象键前缀，默认 blog，只允许字母数字 / _ -')
        . '</div>'
        . checkbox('去除图片元数据（EXIF）', 'process_exif', $cfg['process_exif'], 'JPEG 按拍摄方向自动纠正并剥离 GPS 等隐私信息；GIF 跳过（避免丢失动画）')
        . checkbox('自动压缩图片', 'process_compress', $cfg['process_compress'], '超过最长边时等比缩小，JPEG / WebP 按质量重编码')
        . '<div class="form-grid-2">'
        . input('压缩质量', 'compress_quality', (string)$cfg['compress_quality'], 'number', false, '50-95，默认 82')
        . input('压缩最长边', 'compress_max_width', (string)$cfg['compress_max_width'], 'number', false, '600-6000 像素，默认 1920')
        . '</div>';

    if (!function_exists('imagecreatefromstring')) {
        $html .= '<div class="note">服务器未安装 GD 扩展：去 EXIF 与自动压缩不会生效，图片将按原图上传。</div>';
    }

    $html .= '<div class="btn-row">'
        . '<button class="btn" type="submit" name="imgbed_action" value="save">保存设置</button>'
        . '<button class="btn ghost" type="submit" name="imgbed_action" value="test">保存并测试连接</button>'
        . '</div>'
        . '<p style="color:var(--text-muted);font-size:var(--font-size-sm);margin:10px 0 0">「测试连接」会先保存当前表单，再向图床上传一张 1×1 探测图片并尽力删除，用于验证凭据与读写权限。对象键格式为 <code>路径前缀/年/月/16位随机名.扩展名</code>，不存在文件名冲突。</p>'
        . '</form>';
    return $html;
}

// --- 建表 / 卸载 ---
function imgbed_install(array $plugin): void
{
    $t = app_db_types();
    app_db_create_table('plugin_imgbed_files', "id {$t['id']},user_id {$t['uint']} NOT NULL,store_key {$t['string']} NOT NULL,url {$t['string']} NOT NULL,mime {$t['string']} NOT NULL,source {$t['string']} NOT NULL,size {$t['uint']} NOT NULL,created_at {$t['uint']} NOT NULL,name {$t['string']} NOT NULL");
    app_db_create_index('idx_imgbed_created', 'plugin_imgbed_files (created_at)');
}

function imgbed_uninstall(array $plugin, bool $keep_data = true): void
{
    if ($keep_data) return;
    app_db_drop_index('idx_imgbed_created', 'plugin_imgbed_files');
    app_db_drop_table('plugin_imgbed_files');
}

// --- 资源（合并进 plugins.css / plugins.js）---
function imgbed_css(): string
{
    return <<<'CSS'
.imgbed-open{display:inline-flex;align-items:center;gap:4px}
.imgbed-open svg{display:block}
.imgbed-open[disabled]{opacity:.55;cursor:default}
.imgbed-src-title{font-weight:600;margin:16px 0 6px}
.imgbed-thumb{display:block;width:52px;height:52px;object-fit:cover;border:1px solid var(--border);border-radius:6px;background:var(--muted)}
CSS;
}

function imgbed_js(): string
{
    return <<<'JS'
(function () {
  'use strict';
  // 在光标处插入文本（有选区则替换选区），并通知编辑器刷新统计 / 预览。
  function insertAt(ta, text) {
    var s = ta.selectionStart, e = ta.selectionEnd, v = ta.value;
    ta.value = v.slice(0, s) + text + v.slice(e);
    ta.focus();
    ta.selectionStart = ta.selectionEnd = s + text.length;
    ta.dispatchEvent(new Event('input', { bubbles: true }));
  }
  // 写文章页：工具栏按钮触发选图并上传，成功后插入 Markdown。
  function bindUploader() {
    var btn = document.querySelector('[data-imgbed-open]');
    var input = document.querySelector('.imgbed-file');
    if (!btn || !input) return;
    var ta = document.querySelector('.md-editor textarea');
    if (!ta) return;
    var label = btn.querySelector('.imgbed-label');
    var idle = label ? label.textContent : '';
    var busy = false;
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();   // 核心编辑器对 .md-btn 有全局点击委托，阻止其接管
      if (!busy) input.click();
    });
    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      input.value = '';
      if (!file) return;
      busy = true;
      btn.disabled = true;
      if (label) label.textContent = '上传中…';
      var restore = function () { busy = false; btn.disabled = false; if (label) label.textContent = idle; };
      var fd = new FormData();
      fd.append('file', file);
      fd.append('_csrf', input.getAttribute('data-csrf') || '');
      fetch(input.getAttribute('data-url'), {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
      }).then(function (r) { return r.json(); }).then(function (res) {
        if (!res || res.ok !== 1) { alert((res && res.message) || '上传失败，请重试'); return; }
        insertAt(ta, '![' + (res.name || 'image') + '](' + res.url + ')');
        if (res.note) alert(res.note);
      }).catch(function () { alert('网络错误，上传失败，请重试'); }).then(restore);
    });
  }
  // 后台「图床」列表：复制链接（不可用时回退为手动复制弹窗）。
  function bindCopy() {
    document.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('[data-imgbed-copy]') : null;
      if (!btn) return;
      var url = btn.getAttribute('data-imgbed-copy') || '';
      var flash = function () {
        var old = btn.textContent;
        btn.textContent = '已复制';
        setTimeout(function () { btn.textContent = old; }, 1500);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(flash, function () { window.prompt('复制链接：', url); });
      } else {
        window.prompt('复制链接：', url);
      }
    });
  }
  // 配置页：凭据区只显示当前选中源；其余块 hidden 但仍随表单提交（切换源不丢数据），切换即时显隐。
  function bindSourceSwitch() {
    var block = document.querySelector('[data-imgbed-source]');
    var form = block && block.closest ? block.closest('form') : null;
    var sel = form ? form.querySelector('select[name="source"]') : null;
    if (!sel) return;
    var blocks = form.querySelectorAll('[data-imgbed-source]');
    var apply = function () {
      Array.prototype.forEach.call(blocks, function (b) {
        if (b.getAttribute('data-imgbed-source') === sel.value) b.removeAttribute('hidden');
        else b.setAttribute('hidden', '');
      });
    };
    sel.addEventListener('change', apply);
    apply();
  }
  function init() { bindUploader(); bindCopy(); bindSourceSwitch(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
}());
JS;
}

return [
    'id' => 'imgbed',
    'name' => '图床',
    'version' => '1.0.0',
    'description' => '写作图片一键上传到外部图床（阿里云 OSS / 腾讯云 COS / Cloudflare R2 / WebDAV）：写文章页上传后自动插入正文，支持去 EXIF（默认开）与自动压缩（默认关），后台可浏览与管理已上传图片。',
    'author' => 'Mono',
    'assets' => ['css' => 'imgbed_css', 'js' => 'imgbed_js'],
    'hooks' => [
        'page.before_render' => 'imgbed_write_inject',
        'admin.tabs' => 'imgbed_admin_tabs',
    ],
    'routes' => ['imgbed_upload' => 'imgbed_upload_route'],
    'admin_tabs' => ['imgbed' => 'imgbed_admin'],
    'install' => 'imgbed_install',
    'uninstall' => 'imgbed_uninstall',
];
