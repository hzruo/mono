<?php
if (!defined('APP_ROOT')) exit;

/**
 * 搜索插件（search）。
 *
 * 核心已内置基础搜索路由（?a=search），本插件负责把搜索「摆到台面上」并增强体验：
 * - hook top.bar.actions：在导航右侧注入常驻搜索框。
 * - hook page.before_render：搜索结果页对命中的关键词做 <mark> 高亮（仅文本节点，不破坏标签）。
 * - hook sidebar.stack：搜索结果页追加「相关标签」卡片（单次查询，不在循环中查库）。
 * - admin_tabs search：配置占位文案、是否高亮、是否显示相关标签。
 */

function search_config(): array
{
    $raw = plugin_config('search', []);
    return [
        'placeholder' => (string)($raw['placeholder'] ?? '搜索文章…'),
        'highlight' => (int)($raw['highlight'] ?? 1) === 1,
        'related' => (int)($raw['related'] ?? 1) === 1,
    ];
}

// 当前是否处于带关键词的搜索结果页。
function search_active_keyword(): string
{
    if ((string)($_GET['a'] ?? '') !== 'search') return '';
    return trim((string)($_GET['q'] ?? ''));
}

// 导航右侧常驻搜索框。
function search_nav_form(string $value, array $ctx): string
{
    $cfg = search_config();
    $kw = search_active_keyword();
    return $value
        . '<form class="search-box" method="get" action="' . h(route_url('search')) . '" role="search">'
        . '<input type="search" name="q" value="' . h($kw) . '" placeholder="' . h($cfg['placeholder']) . '" aria-label="搜索">'
        . '<button type="submit" class="search-submit" aria-label="搜索">搜索</button></form>';
}

// 仅替换文本节点中的关键词，避免破坏标签与属性。
function search_mark_text(string $html, string $kw): string
{
    $esc = preg_quote($kw, '/');
    return preg_replace_callback('/<[^>]+>|[^<]+/', function ($m) use ($esc) {
        $seg = $m[0];
        if ($seg === '' || $seg[0] === '<') return $seg;
        return preg_replace('/(' . $esc . ')/iu', '<mark>$1</mark>', $seg) ?? $seg;
    }, $html) ?? $html;
}

// 结果页关键词高亮。
function search_highlight(string $value, array $ctx): string
{
    $kw = search_active_keyword();
    if ($kw === '' || !search_config()['highlight']) return $value;
    return search_mark_text($value, $kw);
}

// 结果页「相关标签」卡片。
function search_sidebar_tags(string $value, array $ctx): string
{
    $kw = search_active_keyword();
    if ($kw === '' || !search_config()['related']) return $value;
    $like = '%' . $kw . '%';
    $op = db_driver() === 'pgsql' ? 'ILIKE' : 'LIKE';
    $tags = all("SELECT id,name FROM app_tags WHERE name $op ? ORDER BY id ASC LIMIT 12", [$like]);
    if (!$tags) return $value;
    $links = '';
    foreach ($tags as $t) {
        $links .= '<a class="tag" href="' . h(route_url('tag', ['id' => (int)$t['id']])) . '">' . h((string)$t['name']) . '</a>';
    }
    return $value . '<div class="card"><div class="card-title">相关标签</div><div class="tag-cloud">' . $links . '</div></div>';
}

// 搜索框与高亮样式。
function search_css(): string
{
    return <<<'CSS'
.search-box{display:flex;align-items:center;gap:6px;margin-right:4px}
.search-box input[type=search]{height:32px;width:150px;padding:0 10px;border:1px solid var(--input);border-radius:999px;background:var(--background);color:var(--foreground);font-size:var(--font-size-sm);outline:none;transition:border-color .15s,box-shadow .15s,width .2s}
.search-box input[type=search]:focus{border-color:var(--ring);box-shadow:0 0 0 3px color-mix(in oklab,var(--ring) 22%,transparent);width:190px}
.search-submit{height:32px;padding:0 12px;border:0;border-radius:999px;background:var(--primary);color:var(--primary-foreground);font-size:var(--font-size-sm);cursor:pointer}
.search-submit:hover{background:var(--brand-hover)}
.content mark,.main mark{background:color-mix(in oklab,var(--warning) 30%,transparent);color:inherit;padding:0 2px;border-radius:3px}
@media (max-width:640px){.search-box input[type=search]{width:104px}.search-box input[type=search]:focus{width:132px}}
CSS;
}

// 后台配置页。
function search_admin(array $plugin): string
{
    if (is_post_request()) {
        plugin_save_config('search', [
            'placeholder' => post('placeholder', 60) ?: '搜索文章…',
            'highlight' => (int)($_POST['highlight'] ?? 0) === 1 ? 1 : 0,
            'related' => (int)($_POST['related'] ?? 0) === 1 ? 1 : 0,
        ]);
        set_flash('搜索设置已保存');
        go(admin_url(['tab' => 'plugins', 'view' => 'search']));
    }
    $cfg = search_config();
    return '<form method="post">' . form_token()
        . '<input type="hidden" name="admin_action" value="noop">'
        . input('搜索框占位文案', 'placeholder', $cfg['placeholder'])
        . checkbox('结果关键词高亮', 'highlight', $cfg['highlight'])
        . checkbox('结果页显示相关标签', 'related', $cfg['related'])
        . '<button class="btn" type="submit">保存设置</button></form>';
}

return [
    'id' => 'search',
    'name' => '搜索增强',
    'version' => '1.0.0',
    'description' => '在导航栏提供常驻搜索框，并为搜索结果提供关键词高亮与相关标签推荐。',
    'author' => 'Mono',
    'assets' => ['css' => 'search_css'],
    'hooks' => [
        'top.bar.actions' => 'search_nav_form',
        'page.before_render' => 'search_highlight',
        'sidebar.stack' => 'search_sidebar_tags',
    ],
    'admin_tabs' => ['search' => 'search_admin'],
];
