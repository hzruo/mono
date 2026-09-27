<?php
if (!defined('APP_ROOT')) exit;

/**
 * 媒体嵌入插件（media）。
 *
 * 文章里把媒体链接「独立成行」（裸链接或 [文字](链接)）粘贴，渲染时自动变成嵌入播放器：
 * - 网易云音乐：歌曲（type=2）/ 专辑（type=1）/ 歌单（type=0）走官方 outchain 外链播放器；
 * - 视频网站：哔哩哔哩（player.bilibili.com）、YouTube（embed / 播放列表）、抖音（open.douyin.com）；
 * - 直链文件：mp3/m4a 等音频 → <audio>，mp4/webm 等视频 → <video>；
 * - 短链：v.douyin.com / b23.tv 服务端展开 302 跳转（结果缓存进 plugin_media_links），
 *   再嵌入对应播放器；解析失败或关闭解析时渲染为链接卡片（可配置）；
 * - 每个 iframe 播放器下方附「无法播放？前往…」原链接回落（指向平台规范地址）：
 *   访客网络无法直连该平台（如直连 YouTube）或视频失效时的逃生口；
 * - YouTube 专属（可配置）：占位 + 自动探测网络（可访问时自动换成播放器、开着代理也免点击；
 *   不可访问时保留占位并允许手动点击尝试）、自定义嵌入模板（可指向 Invidious 等实例）、
 *   Shorts 竖屏 9:16、播放列表、t=1m30s 类时间点参数；
 * - 音视频直链加载失败（防盗链 / 链接失效）时在播放器下方给出提示，避免静默失败。
 *
 * 写作侧：后台写作页由 media_js 注入「媒体」按钮，支持把分享文案（抖音/B站/…）整段粘贴、
 * 自动解析出链接插入规范行（无需手动提取链接）。
 *
 * 实现机制（占位符回填，但发生在 markdown 管道内）：
 * 1. markdown.render：先保护围栏代码块与行内代码（其中的链接不参与嵌入），再把独立成行的
 *    媒体链接替换为 \x00MEDIA{token}-{n}\x00 占位符——\x00 包裹的占位符不会被核心的 h()
 *    转义与其它行内规则破坏（与核心代码块的 \x00BLOCK 同思路）；
 * 2. markdown.after：把占位符（连同相邻 <br>）整段替换回最终 iframe/audio/video HTML。
 * 契约（独立成行才嵌入；混在句子里的链接保持普通链接，不做惊喜替换）：
 * - 单行 = 单个裸链接；单行 = 单个 [文字](链接)（链接文字被播放器取代）；
 * - 单行 = 多个裸链接（空格分隔，全部可嵌入时才整体替换）；
 * - 行首可带列表符（- * + / 1.）或引用符（>）：只替换链接部分、保留前缀，
 *   列表 / 引用结构仍由核心渲染（播放器出现在列表项 / 引用块内）。
 *
 * 容错红线：所有识别与短链解析逻辑包在 try/catch 中，任何异常只回退为「原样链接」，
 * 绝不把异常抛给核心（插件任一回调抛异常会被核心整个停用）。
 */

function media_config(): array
{
    $raw = plugin_config('media', []);
    return [
        'youtube_nocookie' => (int)($raw['youtube_nocookie'] ?? 1) === 1,
        'yt_lite' => (int)($raw['yt_lite'] ?? 1) === 1,
        'yt_embed' => trim((string)($raw['yt_embed'] ?? '')),
        'bili_hq' => (int)($raw['bili_hq'] ?? 1) === 1,
        'bili_danmaku' => (int)($raw['bili_danmaku'] ?? 0) === 1,
        'resolve_short' => (int)($raw['resolve_short'] ?? 1) === 1,
        'link_card' => (int)($raw['link_card'] ?? 1) === 1,
    ];
}

// --- markdown 管道：埋点与回填 ---

// markdown.render：保护代码 → 识别独立成行的媒体链接 → 替换为占位符并登记嵌入 HTML。
function media_markdown(string $value, array $ctx): string
{
    $original = $value;
    if ($value === '' || !str_contains($value, '://')) return $value;
    try {
        $token = bin2hex(random_bytes(5));
        // 保护围栏代码块与行内代码：其中的链接保持原样（否则会污染代码块显示）。
        $hold = [];
        $value = preg_replace_callback('/```[\s\S]*?```|`[^`\n]*`/', static function (array $m) use (&$hold): string {
            $hold[] = $m[0];
            return "\x00HOLD" . (count($hold) - 1) . "\x00";
        }, $value) ?? $value;
        // 独立成行的链接 → 占位符。三种写法：
        // a) 单个裸链接；b) 单个 [文字](链接)（链接文字被播放器取代）；
        // c) 多个裸链接（空格分隔，全部可嵌入才整体替换——有不可嵌入项时保持原样，
        //    避免吞掉混排文本）；(\S+ 不含 \r，\r? 兼容 CRLF 行尾)。
        // 行首可带列表符号（- * + / 1.）或引用符号（>）：只替换链接部分、保留前缀，
        // 列表 / 引用结构仍由核心渲染（播放器出现在列表项 / 引用块内）。
        // 占位符首尾用 \x00 包裹防止核心 h() 转义与行内规则破坏；但结束标记必须用 \x01：
        // 核心列表规则会对行内容 trim()，而 trim 默认会剥掉尾部 \x00（导致回填匹配失败）。
        $register = static function (string $html) use ($token): string {
            $n = count($GLOBALS['__media_embeds'][$token] ?? []);
            $GLOBALS['__media_embeds'][$token][] = $html;
            return "\x00MEDIA" . $token . "-" . $n . "\x01";
        };
        $value = preg_replace_callback('/^([\t ]*(?:>[ \t]*)*(?:(?:[-*+]|\d{1,3}[.)])[ \t]+)?)(?:\[([^\]\n]*)\]\((\S+)\)|(\S+(?:[ \t]+\S+)*))[\t ]*\r?$/m', static function (array $m) use ($register): string {
            $prefix = (string)$m[1];
            if (((string)($m[3] ?? '')) !== '') { // 整行 Markdown 链接：链接文字被播放器取代
                $html = media_embed((string)$m[3]);
                return $html === null ? $m[0] : $prefix . $register($html);
            }
            $tokens = preg_split('/[\t ]+/', (string)($m[4] ?? ''));
            if (!is_array($tokens) || $tokens === []) return $m[0];
            if (count($tokens) === 1) {
                $html = media_embed((string)$tokens[0]);
                return $html === null ? $m[0] : $prefix . $register($html);
            }
            $htmls = [];
            foreach ($tokens as $tk) {
                if (!preg_match('~^https?://~i', (string)$tk)) return $m[0]; // 混排文本行：原样保留
                $html = media_embed((string)$tk);
                if ($html === null) return $m[0]; // 含不可嵌入项：整行不动，避免部分替换
                $htmls[] = $html;
            }
            return $prefix . $register(implode('', $htmls));
        }, $value) ?? $value;
        if ($hold) {
            $value = preg_replace_callback('/\x00HOLD(\d+)\x00/', static fn(array $m): string => $hold[(int)$m[1]] ?? '', $value) ?? $value;
        }
        return $value;
    } catch (Throwable $e) {
        error_log('[media] markdown: ' . $e->getMessage());
        return $original;
    }
}

// markdown.after：把占位符（连同相邻 <br>，避免块级元素前后多出空行）替换回嵌入 HTML。
function media_restore(string $html, array $ctx): string
{
    $map = $GLOBALS['__media_embeds'] ?? [];
    if (!$map) return $html;
    unset($GLOBALS['__media_embeds']);
    try {
        foreach ($map as $token => $items) {
            if (!is_array($items) || $items === []) continue;
            $pattern = '/(?:<br\s*\/?>\s*)*\x00MEDIA' . preg_quote((string)$token, '/') . '-(\d+)\x01(?:\s*<br\s*\/?>)*/';
            $html = preg_replace_callback($pattern, static fn(array $m): string => $items[(int)$m[1]] ?? '', $html) ?? $html;
        }
    } catch (Throwable $e) {
        error_log('[media] restore: ' . $e->getMessage());
    }
    return $html;
}

// --- 链接识别与嵌入 HTML 生成 ---

// 识别一条 URL 并生成嵌入 HTML；返回 null 表示不是可嵌入的媒体链接（保持原样）。
function media_embed(string $url): ?string
{
    try {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2000 || !preg_match('~^https?://~i', $url)) return null;
        $cfg = media_config();
        // 1) 直链音视频文件（要求扩展名出现在路径段，查询串里的 .mp3 不算）。
        if (preg_match('~^https?://[^\s?#]+\.(?:mp3|m4a|aac|ogg|oga|opus|wav|flac|weba)(?:[?#]|$)~i', $url)) {
            return media_audio_html($url);
        }
        if (preg_match('~^https?://[^\s?#]+\.(?:mp4|m4v|webm|ogv|mov)(?:[?#]|$)~i', $url)) {
            return media_video_file_html($url);
        }
        // 2) 网易云音乐：song / album / playlist（外链播放器 type 映射 2/1/0 已按官方播放器源码核实）。
        if (preg_match('~^https?://(?:www\.)?music\.163\.com/(?:#/|m/)?(song|album|playlist)\?(?:[^#]*?&)?id=(\d+)~i', $url, $m)) {
            return media_netease_html(strtolower($m[1]), (int)$m[2]);
        }
        // 3) YouTube：watch / youtu.be / shorts / live / embed / 播放列表。
        if (preg_match('~^https?://(?:www\.|m\.|music\.)?youtube\.com/(?:watch\?(?:[^#]*?&)?v=|shorts/|live/|embed/)([A-Za-z0-9_-]{6,})~i', $url, $m)) {
            return media_youtube_html($m[1], $url);
        }
        if (preg_match('~^https?://(?:www\.)?youtu\.be/([A-Za-z0-9_-]{6,})~i', $url, $m)) {
            return media_youtube_html($m[1], $url);
        }
        // 播放列表（playlist?list= 或 watch?list= 且无 v=）→ videoseries 嵌入。
        if (preg_match('~^https?://(?:www\.|m\.|music\.)?youtube\.com/(?:playlist|watch)\?~i', $url)
            && preg_match('~[?&]list=([A-Za-z0-9_-]{10,})~', $url, $m)) {
            return media_youtube_playlist_html($m[1]);
        }
        // 4) 哔哩哔哩：BV 号 / av 号，分 P 参数 p 透传。
        if (preg_match('~^https?://(?:www\.|m\.|player\.)?bilibili\.com/video/(BV[0-9A-Za-z]+|av\d+)~i', $url, $m)) {
            $page = 1;
            if (preg_match('~[?&]p=(\d+)~i', $url, $pm)) $page = max(1, (int)$pm[1]);
            return media_bilibili_html($m[1], $page);
        }
        // 5) 抖音：直链携带视频 ID；短链需服务端展开。
        if (preg_match('~^https?://(?:www\.)?(?:douyin|iesdouyin)\.com/(?:video|share/video|note|share/note)/(\d{10,})~i', $url, $m)) {
            return media_douyin_html($m[1]);
        }
        if (preg_match('~^https?://v\.douyin\.com/[A-Za-z0-9_-]+~i', $url)) {
            if (!$cfg['resolve_short']) return $cfg['link_card'] ? media_card_html('抖音', $url) : null;
            $id = media_short_target($url, 'douyin');
            return $id !== '' ? media_douyin_html($id) : ($cfg['link_card'] ? media_card_html('抖音', $url) : null);
        }
        // 6) 哔哩哔哩短链 b23.tv。
        if (preg_match('~^https?://b23\.tv/[A-Za-z0-9]+~i', $url)) {
            if (!$cfg['resolve_short']) return $cfg['link_card'] ? media_card_html('哔哩哔哩', $url) : null;
            $target = media_short_target($url, 'bili');
            return $target !== '' ? media_bilibili_html($target, 1) : ($cfg['link_card'] ? media_card_html('哔哩哔哩', $url) : null);
        }
        return null;
    } catch (Throwable $e) {
        error_log('[media] embed: ' . $e->getMessage());
        return null;
    }
}

// 音频直链：原生 <audio> + 直链回落链接。
function media_audio_html(string $url): string
{
    $name = media_file_name($url);
    return '<div class="media-embed" data-kind="audio"><audio controls preload="metadata" src="' . h($url) . '">'
        . '<a href="' . h($url) . '" target="_blank" rel="noopener nofollow">' . h($name) . '</a></audio>'
        . '<a class="media-src" href="' . h($url) . '" target="_blank" rel="noopener nofollow">' . h($name) . '</a></div>';
}

// 视频文件直链：原生 <video> + 直链回落链接。
function media_video_file_html(string $url): string
{
    return '<div class="media-embed" data-kind="vfile"><video controls preload="metadata" playsinline src="' . h($url) . '">'
        . '<a href="' . h($url) . '" target="_blank" rel="noopener nofollow">' . h(media_file_name($url)) . '</a></video></div>';
}

// 网易云外链播放器：歌曲为 330×86 小条，专辑/歌单为 330×450 列表。
function media_netease_html(string $kind, int $id): string
{
    $type = ['playlist' => 0, 'album' => 1, 'song' => 2][$kind];
    $label = ['playlist' => '网易云歌单', 'album' => '网易云专辑', 'song' => '网易云音乐'][$kind];
    $height = $type === 2 ? 66 : 430;
    $src = 'https://music.163.com/outchain/player?type=' . $type . '&id=' . $id . '&auto=0&height=' . $height;
    $style = $type === 2 ? 'width:330px;max-width:100%;height:86px' : 'width:330px;max-width:100%;height:450px';
    return '<div class="media-embed" data-kind="music"><iframe src="' . h($src) . '" style="' . $style . '" scrolling="no" allowfullscreen="true" title="' . h($label) . '"></iframe>'
        . media_fallback_link('网易云音乐', 'https://music.163.com/#/' . $kind . '?id=' . $id) . '</div>';
}

// YouTube：自定义嵌入模板（可选，Invidious 等）或官方/无痕域名；t/start 透传（支持 1m30s）；
// Shorts 竖屏 9:16；「点击加载」（lite）开启时先渲染占位（不请求 YouTube），点击后再换成
// 播放器——照顾直连 YouTube 受限的网络环境，避免每个嵌入都空转等待。
function media_youtube_html(string $id, string $url): string
{
    $cfg = media_config();
    $t = media_time_seconds($url);
    $src = media_youtube_src(rawurlencode($id), $t);
    $watch = 'https://www.youtube.com/watch?v=' . rawurlencode($id) . ($t > 0 ? '&t=' . $t : '');
    $vertical = (bool)preg_match('~/shorts/[\w-]+~i', $url);
    $attr = 'data-kind="video"' . ($vertical ? ' data-vertical="1"' : '');
    if ($cfg['yt_lite']) {
        return '<div class="media-embed" ' . $attr . '>'
            . media_lite_html($src, $watch, '点击加载 YouTube 播放器（需能访问 YouTube）') . media_fallback_link('YouTube', $watch) . '</div>';
    }
    return '<div class="media-embed" ' . $attr . '>'
        . '<iframe src="' . h($src) . '" loading="lazy" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>'
        . media_fallback_link('YouTube', $watch) . '</div>';
}

// YouTube 播放列表：videoseries 嵌入（自定义模板同样生效）。
function media_youtube_playlist_html(string $list): string
{
    $cfg = media_config();
    $src = media_youtube_src('videoseries?list=' . rawurlencode($list));
    $watch = 'https://www.youtube.com/playlist?list=' . rawurlencode($list);
    if ($cfg['yt_lite']) {
        return '<div class="media-embed" data-kind="video">'
            . media_lite_html($src, $watch, '点击加载 YouTube 播放列表（需能访问 YouTube）') . media_fallback_link('YouTube', $watch) . '</div>';
    }
    return '<div class="media-embed" data-kind="video">'
        . '<iframe src="' . h($src) . '" loading="lazy" allow="encrypted-media" allowfullscreen></iframe>'
        . media_fallback_link('YouTube', $watch) . '</div>';
}

// 计算 YouTube 嵌入地址：自定义模板（含 {id}，如 https://yewtu.be/embed/{id}）优先，
// 其次按配置选无痕/官方域名；start 秒数按需追加（已有查询串时用 & 连接）。
function media_youtube_src(string $embed_path, int $start = 0): string
{
    $cfg = media_config();
    $tpl = $cfg['yt_embed'];
    if ($tpl !== '' && str_contains($tpl, '{id}') && preg_match('~^https?://~i', $tpl)) {
        $src = str_replace('{id}', $embed_path, $tpl);
    } else {
        $host = $cfg['youtube_nocookie'] ? 'www.youtube-nocookie.com' : 'www.youtube.com';
        $src = 'https://' . $host . '/embed/' . $embed_path;
    }
    if ($start > 0 && !str_contains($embed_path, 'videoseries')) {
        $src .= (str_contains($src, '?') ? '&' : '?') . 'start=' . $start;
    }
    return $src;
}

// 解析 YouTube 分享链接里的时间点参数（t=90 / t=90s / t=1m30s / t=1h2m3s / start=90）。
function media_time_seconds(string $url): int
{
    if (!preg_match('~[?&](?:t|start)=([0-9hms]+)~i', $url, $m)) return 0;
    $v = strtolower($m[1]);
    if (ctype_digit($v)) return (int)$v;
    if (preg_match('~^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$~', $v, $p)) {
        return (int)($p[1] ?? 0) * 3600 + (int)($p[2] ?? 0) * 60 + (int)($p[3] ?? 0);
    }
    return 0;
}

// 点击加载占位（lite/facade）：无脚本访客点击时是前往原站的外链（不请求播放器），
// 有脚本时点击就地换成 iframe（见 media_js）。
function media_lite_html(string $src, string $watch, string $text): string
{
    return '<a class="media-lite" href="' . h($watch) . '" data-src="' . h($src) . '" target="_blank" rel="noopener nofollow">'
        . '<span class="media-lite-play" aria-hidden="true">▶</span>'
        . '<span class="media-lite-text">' . h($text) . '</span></a>';
}

// 哔哩哔哩官方播放器：BV/av 号 + 分 P；高码率与弹幕按配置拼参。
function media_bilibili_html(string $id, int $page): string
{
    $cfg = media_config();
    $q = [];
    if (preg_match('~^BV[0-9A-Za-z]+$~', $id)) $q['bvid'] = $id;
    else $q['aid'] = (string)(int)preg_replace('/\D/', '', $id);
    $q['page'] = $page;
    $q['autoplay'] = 0;
    if ($cfg['bili_hq']) $q['high_quality'] = 1;
    $q['danmaku'] = $cfg['bili_danmaku'] ? 1 : 0;
    $src = 'https://player.bilibili.com/player.html?' . http_build_query($q);
    $watch = 'https://www.bilibili.com/video/' . $id . '/' . ($page > 1 ? '?p=' . $page : '');
    return '<div class="media-embed" data-kind="video"><iframe src="' . h($src) . '" loading="lazy" scrolling="no" allowfullscreen="true" title="哔哩哔哩视频"></iframe>'
        . media_fallback_link('哔哩哔哩', $watch) . '</div>';
}

// 抖音官方开放平台播放器（响应头无嵌入限制；referrerpolicy 为官方示例要求）。
function media_douyin_html(string $id): string
{
    $src = 'https://open.douyin.com/player/video?vid=' . rawurlencode($id) . '&autoplay=0';
    return '<div class="media-embed" data-kind="douyin"><iframe src="' . h($src) . '" loading="lazy" scrolling="no" referrerpolicy="unsafe-url" allowfullscreen title="抖音视频"></iframe>'
        . media_fallback_link('抖音', 'https://www.douyin.com/video/' . $id) . '</div>';
}

// iframe 播放器下方的原链接回落：网络无法直连该平台（如直连 YouTube）或视频失效时的逃生口。
function media_fallback_link(string $label, string $url): string
{
    return '<a class="media-fallback" href="' . h($url) . '" target="_blank" rel="noopener nofollow">无法播放？前往' . h($label) . ' ↗</a>';
}

// 链接卡片：短链无法解析（或关闭解析）时的兜底展示。
function media_card_html(string $label, string $url): string
{
    $host = (string)(parse_url($url, PHP_URL_HOST) ?? '');
    return '<a class="media-card" href="' . h($url) . '" target="_blank" rel="noopener nofollow">'
        . '<span class="media-card-badge">' . h($label) . '</span>'
        . '<span class="media-card-url">' . h($host !== '' ? $host : $url) . '</span>'
        . '<span class="media-card-open">打开 ↗</span></a>';
}

function media_file_name(string $url): string
{
    $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
    $name = rawurldecode(basename($path));
    return $name !== '' && $name !== '/' ? $name : $url;
}

// --- 短链展开（服务端 302 解析 + 缓存）---

// 解析短链并返回目标 ID（抖音视频 ID / B 站 BV 号）；失败返回 ''。
// 结果缓存进 plugin_media_links：成功 7 天，失败 1 小时（避免反复空转请求）。
function media_short_target(string $url, string $kind): string
{
    $key = sha1($url);
    try {
        $row = one('SELECT target, updated_at FROM plugin_media_links WHERE url_key=?', [$key]);
        if ($row) {
            $cached = (string)$row['target'];
            $ttl = $cached !== '' ? 604800 : 3600;
            if (now() - (int)$row['updated_at'] <= $ttl) return $cached;
        }
    } catch (Throwable $e) {
        error_log('[media] cache-read: ' . $e->getMessage());
    }
    $location = media_http_location($url);
    $target = '';
    if ($location !== '') {
        if ($kind === 'douyin' && preg_match('~/(?:share/video|shared/video|video|share/note|note)/(\d{10,})~', $location, $m)) $target = $m[1];
        if ($kind === 'bili' && preg_match('~bilibili\.com/video/(BV[0-9A-Za-z]+|av\d+)~i', $location, $m)) $target = $m[1];
    }
    try {
        app_db_upsert('plugin_media_links', ['url_key' => $key, 'target' => $target, 'updated_at' => now()], ['url_key']);
    } catch (Throwable $e) {
        error_log('[media] cache-write: ' . $e->getMessage());
    }
    return $target;
}

// 读取一次 302 跳转的 Location（不跟随）；优先 curl，退回 stream。
function media_http_location(string $url): string
{
    $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) return '';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT => $ua,
        ]);
        curl_exec($ch);
        $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        return $loc;
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'follow_location' => 0,
        'max_redirects' => 0,
        'timeout' => 6,
        'ignore_errors' => true,
        'header' => "User-Agent: {$ua}\r\n",
    ]]);
    @file_get_contents($url, false, $ctx);
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('~^location:\s*(\S+)~i', (string)$header, $m)) return $m[1];
    }
    return '';
}

// --- 安装 / 卸载 ---

function media_install(array $plugin): void
{
    $t = app_db_types();
    app_db_create_table('plugin_media_links', "id {$t['id']},url_key {$t['key']} NOT NULL,target {$t['key']} NOT NULL,updated_at {$t['uint']} NOT NULL,UNIQUE (url_key)");
}

function media_uninstall(array $plugin, bool $keep_data = true): void
{
    if (!$keep_data) app_db_drop_table('plugin_media_links');
}

// --- 前端样式（assets css，合并进 plugins.css）---

function media_css(): string
{
    return <<<'CSS'
.media-embed{margin:18px 0}
.media-embed iframe{display:block;width:100%;border:0}
.media-embed[data-kind="video"] iframe{aspect-ratio:16/9;background:#000}
.media-embed[data-kind="douyin"]{max-width:min(380px,100%);margin-inline:auto}
.media-embed[data-kind="douyin"] iframe{aspect-ratio:9/16;background:#000}
.media-embed[data-vertical="1"]{max-width:min(380px,100%);margin-inline:auto}
.media-embed[data-vertical="1"] iframe{aspect-ratio:9/16}
.media-lite{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;aspect-ratio:16/9;background:#0f0f0f;border-radius:var(--radius);text-decoration:none;color:#fff}
.media-embed[data-vertical="1"] .media-lite{aspect-ratio:9/16}
.media-lite-play{display:flex;align-items:center;justify-content:center;box-sizing:border-box;width:54px;height:54px;padding-left:4px;border-radius:50%;background:rgba(255,255,255,.16);font-size:18px}
.media-lite:hover .media-lite-play{background:var(--brand)}
.media-lite-text{font-size:var(--font-size-sm);color:#d9d9d9}
.media-embed[data-kind="music"] iframe{background:transparent}
.media-embed[data-kind="audio"]{padding:12px 14px;border:1px solid var(--border);border-radius:var(--radius);background:var(--card)}
.media-embed[data-kind="audio"] audio{display:block;width:100%}
.media-embed .media-src{display:inline-block;margin-top:8px;font-size:var(--font-size-xs);color:var(--text-subtle);text-decoration:none;word-break:break-all}
.media-embed .media-src:hover{color:var(--brand)}
.media-embed .media-fallback{display:inline-block;margin-top:6px;font-size:var(--font-size-xs);color:var(--text-subtle);text-decoration:none}
.media-embed .media-fallback:hover{color:var(--brand)}
.media-embed .media-playfail{margin:8px 0 0;font-size:var(--font-size-xs);color:var(--danger)}
.media-embed video{display:block;width:100%;max-height:72vh;border-radius:var(--radius);background:#000}
.media-card{display:flex;align-items:center;gap:10px;margin:18px 0;padding:12px 14px;border:1px solid var(--border);border-radius:var(--radius);background:var(--card);text-decoration:none;color:var(--text)}
.media-card:hover{border-color:var(--brand)}
.media-card-badge{flex:none;padding:2px 10px;border-radius:999px;background:var(--brand-soft);color:var(--brand);font-size:var(--font-size-xs);font-weight:600}
.media-card-url{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text-muted);font-size:var(--font-size-sm)}
.media-card-open{flex:none;color:var(--text-subtle);font-size:var(--font-size-xs)}
.media-dlg-mask{position:fixed;inset:0;z-index:99;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.45)}
.media-dlg{width:min(540px,100%);padding:18px;border:1px solid var(--border);border-radius:var(--radius);background:var(--card);box-shadow:0 12px 40px rgba(0,0,0,.28)}
.media-dlg-title{margin:0 0 8px;font-size:var(--font-size-lg)}
.media-dlg-hint{margin:0 0 10px;font-size:var(--font-size-xs);color:var(--text-muted)}
.media-dlg textarea{width:100%;min-height:110px;box-sizing:border-box;padding:10px;border:1px solid var(--border);border-radius:var(--radius);background:var(--card);color:var(--text);font:inherit;resize:vertical}
.media-dlg-found{min-height:1.2em;margin:10px 0 0;font-size:var(--font-size-xs);color:var(--text-muted);word-break:break-all}
.media-dlg-found b{color:var(--brand)}
.media-dlg-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:12px}
CSS;
}

// --- 前端脚本（assets js，合并进 plugins.js）---

// 1) 前台：点击加载占位（.media-lite）→ 点击 / 自动探测网络可达后就地换成 iframe；
// 2) 写作页：注入「媒体」工具栏按钮，粘贴分享文案自动解析出媒体链接后插入规范行。
function media_js(): string
{
    return <<<'JS'
(function () {
  'use strict';

  // --- 前台：点击加载（lite）占位 ---
  // ① 点击占位 → 就地换成 iframe；② 页面加载后自动探测一次网络：可达则直接全部换成播放器
  // （开着代理的访客无需再点），不可达则保留占位（仍可手动点击尝试）。探测结果会话内缓存。
  function swapLite(a) {
    var src = a.getAttribute('data-src');
    if (!src) return;
    var f = document.createElement('iframe');
    f.src = src;
    f.setAttribute('loading', 'lazy');
    f.setAttribute('allow', 'accelerometer; encrypted-media; gyroscope; picture-in-picture');
    f.setAttribute('allowfullscreen', '');
    a.parentNode.replaceChild(f, a);
  }

  function swapAllLite() {
    var list = document.querySelectorAll('.media-lite');
    for (var i = 0; i < list.length; i++) swapLite(list[i]);
  }

  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('.media-lite') : null;
    if (!a) return;
    e.preventDefault();
    swapLite(a);
  });

  (function autoLoadLite() {
    var first = document.querySelector('.media-lite');
    if (!first || !window.fetch || !window.AbortController) return;
    var src = first.getAttribute('data-src');
    if (!src) return;
    var cached = null;
    try { cached = sessionStorage.getItem('mb_media_lite'); } catch (err) {}
    if (cached === '1') { swapAllLite(); return; }
    if (cached === '0') return;
    var ctl = new AbortController();
    var timer = setTimeout(function () { ctl.abort(); }, 3500);
    fetch(src, { mode: 'no-cors', signal: ctl.signal }).then(function () {
      clearTimeout(timer);
      try { sessionStorage.setItem('mb_media_lite', '1'); } catch (err) {}
      swapAllLite();
    }).catch(function () {
      clearTimeout(timer);
      try { sessionStorage.setItem('mb_media_lite', '0'); } catch (err) {}
    });
  })();

  // --- 前台：音视频直链加载失败提示（防盗链 / 链接失效 / 混合内容）---
  document.addEventListener('error', function (e) {
    var m = e.target;
    if (!m || !m.tagName || (m.tagName !== 'AUDIO' && m.tagName !== 'VIDEO')) return;
    var box = m.closest ? m.closest('.media-embed') : null;
    if (!box || box.querySelector('.media-playfail')) return;
    var tip = document.createElement('p');
    tip.className = 'media-playfail';
    tip.textContent = '媒体加载失败：源站可能限制外部引用或链接已失效，可点击上方链接直接访问源文件。';
    box.appendChild(tip);
  }, true);

  // --- 写作页：分享文案 → 媒体链接 ---
  // 排除空白/引号/尖括号/标点与汉字（粘贴文本里 URL 通常以空格或中文接续，
  // URL 中出现原样中文必然是被夹带的说明文字）。
  var URL_RE = /https?:\/\/[^\s"'<>\u4e00-\u9fff\u3000-\u303f\uff00-\uffef【】（）]+/g;
  var HOST_RE = /(?:^|\.)(?:music\.163\.com|bilibili\.com|b23\.tv|douyin\.com|iesdouyin\.com|youtube\.com|youtu\.be)$/i;
  var FILE_RE = /\.(?:mp3|m4a|aac|ogg|oga|opus|wav|flac|weba|mp4|m4v|webm|ogv|mov)(?:[?#]|$)/i;

  function pickUrls(text) {
    var out = [];
    var seen = {};
    var m = String(text || '').match(URL_RE) || [];
    for (var i = 0; i < m.length; i++) {
      var t = m[i].replace(/[\u200b-\u200d\ufeff]/g, '');
      var u;
      do { u = t; t = u.replace(/[)\]}>.,;!?"']+$/, ''); } while (t !== u && t.length > 8);
      try { if (!HOST_RE.test(new URL(t).hostname) && !FILE_RE.test(t)) continue; } catch (err) { continue; }
      if (seen[t]) continue;
      seen[t] = 1;
      out.push(t);
    }
    return out;
  }

  function esc(s) {
    return String(s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  // 按「独立成行」契约插入：前后补换行，把整块 URL 顶到独立行。
  function insertLines(ta, lines) {
    var s = ta.selectionStart, e = ta.selectionEnd, v = ta.value;
    var pre = (s > 0 && v.charAt(s - 1) !== '\n') ? '\n' : '';
    var post = (e < v.length && v.charAt(e) !== '\n') ? '\n' : '';
    var text = lines.join('\n');
    ta.value = v.slice(0, s) + pre + text + post + v.slice(e);
    ta.focus();
    ta.selectionStart = ta.selectionEnd = s + pre.length + text.length;
    ta.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function openDialog(ta) {
    var mask = document.createElement('div');
    mask.className = 'media-dlg-mask';
    mask.innerHTML = '<div class="media-dlg" role="dialog" aria-label="插入媒体">'
      + '<h3 class="media-dlg-title">插入媒体</h3>'
      + '<p class="media-dlg-hint">把分享内容整段粘贴到这里（网易云 / 哔哩哔哩 / 抖音 / YouTube 的分享文案或链接，支持多个），自动解析出链接并按「独立成行」插入。</p>'
      + '<textarea rows="5" placeholder="例如：6.48 复制打开抖音，看看【某某的作品】… https://v.douyin.com/xxxx/"></textarea>'
      + '<p class="media-dlg-found"></p>'
      + '<div class="media-dlg-actions"><button type="button" class="btn ghost" data-x="cancel">取消</button>'
      + '<button type="button" class="btn" data-x="ok" disabled>插入</button></div></div>';
    var box = mask.querySelector('.media-dlg');
    var input = box.querySelector('textarea');
    var found = box.querySelector('.media-dlg-found');
    var ok = box.querySelector('[data-x="ok"]');
    var urls = [];
    var refresh = function () {
      urls = pickUrls(input.value);
      if (urls.length) {
        found.innerHTML = '识别到 <b>' + urls.length + '</b> 个链接：' + urls.map(esc).join('&nbsp; ');
        ok.disabled = false;
      } else {
        found.textContent = input.value.trim() ? '未识别到支持的媒体链接（支持网易云 / 哔哩哔哩 / 抖音 / YouTube / 音视频直链）' : '';
        ok.disabled = true;
      }
    };
    var close = function () {
      document.removeEventListener('keydown', onKey);
      mask.remove();
    };
    var onKey = function (e) { if (e.key === 'Escape') close(); };
    input.addEventListener('input', refresh);
    mask.addEventListener('click', function (e) {
      if (e.target === mask) { close(); return; }
      var x = e.target.closest ? e.target.closest('[data-x]') : null;
      if (!x) return;
      if (x.getAttribute('data-x') === 'cancel') { close(); return; }
      if (urls.length) { insertLines(ta, urls); close(); }
    });
    document.addEventListener('keydown', onKey);
    document.body.appendChild(mask);
    input.focus();
  }

  // 写作页工具栏注入「媒体」按钮（核心编辑器对未知 data-md 安全忽略，点击自行处理）。
  (function initEditor() {
    var editor = document.querySelector('.md-editor');
    if (!editor || editor.querySelector('[data-media-btn]')) return;
    var ta = editor.querySelector('textarea');
    var bar = editor.querySelector('.md-toolbar');
    if (!ta || !bar) return;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'md-btn';
    btn.setAttribute('data-media-btn', '1');
    btn.title = '插入媒体：粘贴分享内容自动解析（网易云 / 哔哩哔哩 / 抖音 / YouTube）';
    btn.textContent = '媒体';
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      openDialog(ta);
    });
    var spacer = bar.querySelector('.md-spacer');
    bar.insertBefore(btn, spacer || null);
  })();
})();
JS;
}

// --- 后台配置页 ---

function media_admin(array $plugin): string
{
    if (is_post_request()) {
        $yt_embed = trim((string)($_POST['yt_embed'] ?? ''));
        $tpl_bad = $yt_embed !== '' && (!preg_match('~^https?://~i', $yt_embed) || !str_contains($yt_embed, '{id}'));
        if ($tpl_bad) $yt_embed = '';
        plugin_save_config('media', [
            'youtube_nocookie' => (int)($_POST['youtube_nocookie'] ?? 0) === 1 ? 1 : 0,
            'yt_lite' => (int)($_POST['yt_lite'] ?? 0) === 1 ? 1 : 0,
            'yt_embed' => $yt_embed,
            'bili_hq' => (int)($_POST['bili_hq'] ?? 0) === 1 ? 1 : 0,
            'bili_danmaku' => (int)($_POST['bili_danmaku'] ?? 0) === 1 ? 1 : 0,
            'resolve_short' => (int)($_POST['resolve_short'] ?? 0) === 1 ? 1 : 0,
            'link_card' => (int)($_POST['link_card'] ?? 0) === 1 ? 1 : 0,
        ]);
        set_flash($tpl_bad ? '已保存；自定义嵌入模板需以 http(s):// 开头且包含 {id}，该输入已忽略' : '媒体嵌入设置已保存');
        go(admin_url(['tab' => 'plugins', 'view' => 'media']));
    }
    $cfg = media_config();
    $note = '<div class="note">写作页工具栏有「媒体」按钮：把分享文案整段粘贴即可自动解析出链接（无需手动提取）。'
        . '手动插入的规则：链接需<strong>独立成行</strong>（<code>https://…</code> 或 <code>[文字](链接)</code>，'
        . '链接文字会被播放器取代；一行多个链接会被整体识别；列表项 / 引用块里的独立链接行同样生效）；混在句子里的链接保持普通链接。示例：<br>'
        . '<code>https://music.163.com/#/song?id=38019459</code>（网易云歌曲 / 专辑 / 歌单）<br>'
        . '<code>https://www.bilibili.com/video/BV1SUhe6xEM2/</code>（哔哩哔哩，含 b23.tv 短链）<br>'
        . '<code>https://v.douyin.com/7ziPVgRU3S4/</code>（抖音，含短链）<br>'
        . '<code>https://youtube.com/shorts/I0bJ983oMWE</code>（YouTube：视频 / Shorts / 播放列表）<br>'
        . '<code>https://example.com/song.mp3</code>（音频直链：mp3/m4a/ogg/wav/flac 等；视频直链：mp4/webm 等——示例域名仅示意，请替换为真实文件地址）</div>';
    return $note
        . '<form method="post">' . form_token()
        . '<input type="hidden" name="admin_action" value="noop">'
        . checkbox('YouTube 使用无痕域名', 'youtube_nocookie', $cfg['youtube_nocookie'], '嵌入域改为 youtube-nocookie.com，减少跟踪 Cookie（设置自定义模板时此项无效）')
        . checkbox('YouTube 点击加载（lite）', 'yt_lite', $cfg['yt_lite'], '先显示占位并自动探测网络：能访问时自动加载播放器（开着代理也免点击），不能访问时保留占位、点击仍可尝试；关闭则始终直接嵌入播放器')
        . input('YouTube 自定义嵌入模板（可选）', 'yt_embed', $cfg['yt_embed'], 'text', false, '含 {id} 的完整地址，如 https://yewtu.be/embed/{id}（Invidious 等实例）；留空用官方域名')
        . checkbox('哔哩哔哩高码率优先', 'bili_hq', $cfg['bili_hq'], '未登录时可能自动降级')
        . checkbox('哔哩哔哩默认开启弹幕', 'bili_danmaku', $cfg['bili_danmaku'], '默认关闭，登录后表现与 B 站一致')
        . checkbox('解析短链', 'resolve_short', $cfg['resolve_short'], '服务端展开 v.douyin.com / b23.tv 并嵌入播放器；首次渲染需等待跳转（结果会缓存）')
        . checkbox('无法嵌入时显示链接卡片', 'link_card', $cfg['link_card'], '短链解析失败（或未开启解析）时，把链接渲染成卡片而不是纯文本')
        . '<button class="btn" type="submit">保存设置</button></form>';
}

return [
    'id' => 'media',
    'name' => '媒体嵌入',
    'version' => '1.1.1',
    'description' => '把独立成行的媒体链接自动变成播放器：网易云音乐、哔哩哔哩、YouTube、抖音与音视频直链；写作页「媒体」按钮支持粘贴分享文案自动解析。',
    'author' => 'Mono',
    'assets' => ['css' => 'media_css', 'js' => 'media_js'],
    'hooks' => [
        'markdown.render' => 'media_markdown',
        'markdown.after' => 'media_restore',
    ],
    'admin_tabs' => ['media' => 'media_admin'],
    'install' => 'media_install',
    'uninstall' => 'media_uninstall',
];
