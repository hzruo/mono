<?php
if (!defined('APP_ROOT')) exit;

/**
 * 暖纸主题（paper）—— 整站主题插件。
 *
 * 参考编辑风博客（暖纸底色 + 陶土橙强调 + 衬线标题 + 单栏居中阅读）重做全站观感，零核心改动：
 * - hook page.head：注入完整语义令牌（:root 亮色 / .dark 暗色）与显示字体变量，覆盖核心 zinc 中性配色。
 * - hook page.template：可选为 <html> 追加 .dark 类（站点默认暗色；访客仍可用导航栏按钮切换）。
 * - hook page.before_render：首页顶部注入一行站点描述作引言（未设置描述时展示站点名称），不新增内容。
 * - assets css：单栏居中、侧栏下移、衬线标题、磨砂顶栏、纸面卡片等结构性增强（合并进 plugins.css）。
 * - admin_tabs paper：后台调强调色预设 / 自定义色、衬线标题、首页引言与默认暗色。
 *
 * 颜色只覆盖核心 index.css 的语义令牌（--primary/--background/--border...），组件规则沿用核心，
 * 因此与既有页面、其它插件天然兼容。令牌经 page.head 内联注入，晚于 index.css 与 plugins.css 生效。
 */

function paper_config(): array
{
    $raw = plugin_config('paper', []);
    $preset = (string)($raw['preset'] ?? 'terracotta');
    if (!array_key_exists($preset, paper_accents())) $preset = 'terracotta';
    $accent = trim((string)($raw['accent'] ?? ''));
    if ($accent !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) $accent = '';
    return [
        'preset' => $preset,
        'accent' => $accent,
        'serif' => (int)($raw['serif'] ?? 1) === 1,
        'hero' => (int)($raw['hero'] ?? 1) === 1,
        'dark' => (int)($raw['dark'] ?? 0) === 1,
    ];
}

// 强调色预设：均为与暖纸底色相容的暖调，default 为参考站同款陶土橙。
// light/dark 为亮/暗模式主色，on_light/on_dark 为其上的前景色（按钮文字）。
function paper_accents(): array
{
    return [
        'terracotta' => ['名称' => '陶土橙（默认）', 'light' => '#e85d3f', 'dark' => '#f07552', 'on_light' => '#ffffff', 'on_dark' => '#1a100c'],
        'brick' => ['名称' => '砖红', 'light' => '#c2452f', 'dark' => '#e2684f', 'on_light' => '#ffffff', 'on_dark' => '#1a100c'],
        'amber' => ['名称' => '琥珀', 'light' => '#b8762a', 'dark' => '#e2a355', 'on_light' => '#ffffff', 'on_dark' => '#1a100c'],
        'sage' => ['名称' => '松绿', 'light' => '#5f7f63', 'dark' => '#8fb383', 'on_light' => '#ffffff', 'on_dark' => '#0f1a10'],
        'ink' => ['名称' => '墨黑（极简）', 'light' => '#26211c', 'dark' => '#e8dfd2', 'on_light' => '#fffdf8', 'on_dark' => '#1a100c'],
    ];
}

// 自定义强调色按相对亮度自动选前景（深色底配白字、浅色底配深字），避免按钮文字看不清。
function paper_accent_colors(array $cfg): array
{
    $a = paper_accents()[$cfg['preset']];
    if ($cfg['accent'] !== '') {
        $hex = $cfg['accent'];
        $a['light'] = $hex;
        $a['dark'] = $hex;
        $a['on_light'] = paper_luminance($hex) > 0.62 ? '#1a100c' : '#ffffff';
        $a['on_dark'] = paper_luminance($hex) > 0.45 ? '#1a100c' : '#ffffff';
    }
    return $a;
}

function paper_luminance(string $hex): float
{
    $lin = static function (float $c): float {
        $c /= 255;
        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };
    return 0.2126 * $lin((float)hexdec(substr($hex, 1, 2)))
        + 0.7152 * $lin((float)hexdec(substr($hex, 3, 2)))
        + 0.0722 * $lin((float)hexdec(substr($hex, 5, 2)));
}

function paper_serif_stack(): string
{
    return 'Georgia,"Times New Roman","Noto Serif SC","Source Han Serif SC","Songti SC",STSong,"SimSun",serif';
}

// 在 <head> 注入令牌覆盖：先 :root（亮色）后 .dark（暗色）。内联样式晚于 index.css/plugins.css，稳定胜出。
function paper_head(string $value, array $ctx): string
{
    $cfg = paper_config();
    $a = paper_accent_colors($cfg);
    $display = $cfg['serif'] ? paper_serif_stack()
        : '-apple-system,BlinkMacSystemFont,"PingFang SC","Hiragino Sans GB","Microsoft YaHei","Noto Sans SC","Segoe UI",sans-serif';

    $light = [
        '--background:#fbf7ef', '--foreground:#3f3831',
        '--card:#fffdf8', '--card-foreground:#3f3831',
        '--popover:#fffdf8', '--popover-foreground:#3f3831',
        '--primary:' . $a['light'], '--primary-foreground:' . $a['on_light'],
        '--secondary:#f4ede2', '--secondary-foreground:#3f3831',
        '--muted:#f4ede2', '--muted-foreground:#756f66',
        '--accent:#f2e9db', '--accent-foreground:#2b251f',
        '--destructive:#c2452f', '--destructive-foreground:#fffdf8',
        '--border:#eadfce', '--input:#e0cfb9', '--ring:' . $a['light'],
        '--success:#6f8c73', '--warning:#c98a2e',
        '--radius:0.75rem', '--text-subtle:#9b9489',
        '--shadow-sm:0 1px 2px rgba(89,61,34,.06)',
        '--shadow:0 2px 6px -1px rgba(89,61,34,.08),0 1px 2px rgba(89,61,34,.05)',
        '--shadow-md:0 12px 30px -6px rgba(89,61,34,.16)',
        '--paper-ink:#15120f',
        '--paper-display:' . $display,
    ];
    $dark = [
        '--background:#13110f', '--foreground:#d8cfc3',
        '--card:#1c1916', '--card-foreground:#d8cfc3',
        '--popover:#2e2822', '--popover-foreground:#f7efe5',
        '--primary:' . $a['dark'], '--primary-foreground:' . $a['on_dark'],
        '--secondary:#26211c', '--secondary-foreground:#d8cfc3',
        '--muted:#26211c', '--muted-foreground:#a79d8f',
        '--accent:#2e2822', '--accent-foreground:#f7efe5',
        '--destructive:#f07552', '--destructive-foreground:#1a100c',
        '--border:rgba(167,157,143,.18)', '--input:rgba(167,157,143,.32)', '--ring:' . $a['dark'],
        '--success:#8fb383', '--warning:#eeb15f',
        '--text-subtle:#81786d',
        '--shadow-sm:0 1px 2px rgba(0,0,0,.4)',
        '--shadow:0 2px 8px rgba(0,0,0,.45)',
        '--shadow-md:0 18px 40px rgba(0,0,0,.5)',
        '--paper-ink:#f7efe5',
    ];
    $css = ':root{' . implode(';', $light) . '}' . '.dark{' . implode(';', $dark) . '}';
    return $value . '<style data-plugin-id="paper">' . $css . '</style>';
}

// 默认暗色：为 <html> 追加 .dark 类（幂等，已有则不重复追加）。
function paper_template(string $value, array $ctx): string
{
    if (!paper_config()['dark']) return $value;
    if (preg_match('/<html[^>]*\bclass="[^"]*\bdark\b/i', $value)) return $value;
    if (preg_match('/<html[^>]*\bclass="/i', $value)) {
        return preg_replace('/(<html[^>]*\bclass=")/i', '$1dark ', $value, 1) ?? $value;
    }
    return preg_replace('/<html(\s[^>]*)?>/i', '<html$1 class="dark">', $value, 1) ?? $value;
}

// 首页引言：仅在首页第 1 页注入站点描述作标题（未设置描述时展示站点名称；不与顶栏品牌重复）。
function paper_hero(string $value, array $ctx): string
{
    if (!paper_config()['hero']) return $value;
    if (($_GET['a'] ?? 'home') !== 'home' || current_page() > 1) return $value;
    $marker = '<div class="main" data-slot="page.before_render">';
    if (!str_contains($value, $marker)) return $value;
    $name = trim(setting('site_name', 'Mono'));
    $desc = trim(setting('site_description', ''));
    if ($name === '' && $desc === '') return $value;
    $hero = '<header class="paper-hero">'
        . '<h1 class="paper-hero-title">' . h($desc !== '' ? $desc : $name) . '</h1>'
        . '<span class="paper-hero-rule"></span></header>';
    return str_replace($marker, $marker . $hero, $value);
}

// 结构性增强 CSS（作为插件 assets 合并进 plugins.css，晚于 index.css 生效）。
function paper_css(): string
{
    return <<<'CSS'
/* 1. 字体：正文走系统无衬线栈，标题走 --paper-display（后台可关衬线） */
body{font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Hiragino Sans GB","Microsoft YaHei","Noto Sans SC","Segoe UI",sans-serif;background-image:radial-gradient(1100px 520px at 50% -10%,color-mix(in oklab,var(--primary) 7%,transparent),transparent 62%);background-attachment:fixed}
h1,h2,h3,h4{letter-spacing:-.012em}
.brand,.post-title,.content h1,.content h2,.content h3,.content h4{font-family:var(--paper-display);color:var(--paper-ink)}

/* 2. 单栏居中：前台单列化，版心沿用核心 1100px——与后台/写文章页、顶栏、页脚完全同宽 */
.wrap{grid-template-columns:1fr}

/* 3. 侧栏下移：变为内容区下方的站点导航区（去卡片化，分类/标签/归档仍可达） */
.sidebar{position:static;display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:26px 28px;margin-top:16px;padding-top:26px;border-top:1px solid var(--border)}
.sidebar>.card{background:none;border:0;box-shadow:none;padding:0}
.sidebar>.card+.card{margin-top:0}
.sidebar .card-title{font-family:inherit;font-size:var(--font-size-sm);letter-spacing:.06em;color:var(--muted-foreground);margin-bottom:10px}
.side-list li{padding:8px 0}

/* 3.1 目录配合（toc）：侧栏移到底部后侧栏目录卡失去意义——全宽启用正文顶部折叠目录，存在时隐藏侧栏目录卡 */
.wrap .toc-inline{display:block;border-radius:12px}
.wrap:has(.toc-inline) .sidebar .toc-card{display:none}

/* 4. 顶栏与页脚：暖色磨砂 */
.top{background:color-mix(in oklab,var(--background) 78%,transparent);backdrop-filter:saturate(120%) blur(18px);-webkit-backdrop-filter:saturate(120%) blur(18px)}
.brand-mark{border-radius:8px;box-shadow:0 1px 2px rgba(89,61,34,.18)}

/* 5. 纸面卡片；文章列表卡去壳，每条独立成纸卡 */
.card{border-radius:14px;box-shadow:var(--shadow)}
.card:has(>.post-item){background:none;border-color:transparent;box-shadow:none;padding:0}
.post-item{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:22px 24px;box-shadow:var(--shadow-sm);transition:border-color .18s ease,box-shadow .18s ease}
.post-item+.post-item{margin-top:14px}
.post-item:first-child{padding-top:22px}
.post-item:last-child{border-bottom:1px solid var(--border);padding-bottom:22px}
.post-item:hover{border-color:color-mix(in oklab,var(--primary) 30%,var(--border));box-shadow:var(--shadow-md)}
.post-title{font-size:21px;line-height:1.42;font-weight:600}
.post-title a:hover{color:var(--primary);opacity:1}

/* 6. 文章详情与正文 */
.post-detail h1.post-title{font-size:33px;line-height:1.3;font-weight:600}
.content{font-size:16.5px;line-height:1.85;color:var(--foreground)}
.content p{margin:1.05em 0}
.content h2{font-size:21px}
.content h3{font-size:18px}
.content img{border-radius:12px}
.content blockquote{border-left-color:var(--primary);background:color-mix(in oklab,var(--primary) 6%,transparent);border-radius:0 12px 12px 0;padding:13px 18px;font-style:normal}
.content code{color:color-mix(in oklab,var(--paper-ink) 76%,var(--primary))}
.content th{background:color-mix(in oklab,var(--primary) 6%,var(--muted))}
.content .codeblock-bar{background:#241d17}
.content pre{background:#221c17;border-color:rgba(255,255,255,.09)}
html.dark .content .codeblock-bar{background:#0f0d0b}
html.dark .content pre{background:#0f0d0b}

/* 7. 组件微调 */
.btn{border-radius:9px}
.pager a,.pager span,.pager .current{border-radius:10px}
.tag{border-color:color-mix(in oklab,var(--border) 85%,transparent)}

/* 8. 首页引言（page.before_render 注入） */
.paper-hero{padding:6px 0 30px}
.paper-hero-title{margin:0;font-family:var(--paper-display);font-size:30px;line-height:1.45;font-weight:600;color:var(--paper-ink);text-wrap:balance}
.paper-hero-rule{display:block;width:44px;height:3px;margin-top:18px;border-radius:3px;background:var(--primary)}

/* 9. 暗色暖调细节 */
html.dark ::-webkit-scrollbar-thumb{background:rgba(240,117,82,.3)}

/* 10. 响应式 */
@media (max-width:640px){
  .sidebar{grid-template-columns:1fr;gap:22px}
  .post-item{padding:18px;border-radius:12px}
  .post-detail h1.post-title{font-size:26px}
  .paper-hero-title{font-size:24px}
}
CSS;
}

// 后台主题配置页。
function paper_admin(array $plugin): string
{
    if (is_post_request()) {
        $preset = (string)($_POST['preset'] ?? 'terracotta');
        if (!array_key_exists($preset, paper_accents())) $preset = 'terracotta';
        $accent = trim((string)($_POST['accent'] ?? ''));
        if ($accent !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) $accent = '';
        plugin_save_config('paper', [
            'preset' => $preset,
            'accent' => $accent,
            'serif' => (int)($_POST['serif'] ?? 0) === 1 ? 1 : 0,
            'hero' => (int)($_POST['hero'] ?? 0) === 1 ? 1 : 0,
            'dark' => (int)($_POST['dark'] ?? 0) === 1 ? 1 : 0,
        ]);
        set_flash('暖纸主题已更新');
        go(admin_url(['tab' => 'plugins', 'view' => 'paper']));
    }
    $cfg = paper_config();
    $options = [];
    foreach (paper_accents() as $key => $a) $options[$key] = $a['名称'];
    return '<form method="post">' . form_token()
        . '<input type="hidden" name="admin_action" value="noop">'
        . select_input('强调色预设', 'preset', $cfg['preset'], $options, '用于链接、按钮、标签与选中态')
        . input('自定义强调色', 'accent', $cfg['accent'], 'text', false, '留空则用预设色，格式 #RRGGBB；填了则覆盖预设')
        . checkbox('衬线标题', 'serif', $cfg['serif'], '文章标题与正文小标题使用衬线字体（Georgia / 宋体栈），关闭则全部用无衬线')
        . checkbox('首页引言', 'hero', $cfg['hero'], '首页顶部展示一行站点描述（取自「设置 → 站点描述」），留空则展示站点名称')
        . checkbox('默认暗色', 'dark', $cfg['dark'], '站点默认进入暗色；访客仍可通过导航栏按钮自行切换深浅色')
        . '<button class="btn" type="submit">保存主题</button></form>';
}

return [
    'id' => 'paper',
    'name' => '暖纸主题',
    'version' => '1.0.3',
    'description' => '编辑风整站主题：暖纸底色 + 陶土橙强调 + 衬线标题 + 单栏居中阅读，含磨砂顶栏、纸面卡片与亮暗双色，可自定义强调色。',
    'author' => 'Mono',
    'assets' => ['css' => 'paper_css'],
    'hooks' => [
        'page.head' => 'paper_head',
        'page.template' => 'paper_template',
        'page.before_render' => 'paper_hero',
    ],
    'admin_tabs' => ['paper' => 'paper_admin'],
];
