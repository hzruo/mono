<?php
if (!defined('APP_ROOT')) exit;

/**
 * 友情链接插件（friend）。
 *
 * 数据存 plugin_friend_links 表，两处展示（后台可分别开关）：
 * - sidebar.stack：侧栏「友情链接」卡片；
 * - 独立页面：路由 ?a=links（伪静态 /links），顶部导航经 nav.menu_links 注入入口。
 * 图标优先取每条的「自定义图标」，留空则自动取对方站点的 /favicon.ico（加载失败自动退回首字母）。
 * 增删改在后台「插件 → 友情链接 → 配置」完成（本插件无前台写入，路由只读）。
 */

function friend_config(): array
{
    $raw = plugin_config('friend', []);
    return [
        'title' => (string)($raw['title'] ?? '') !== '' ? (string)$raw['title'] : '友情链接',
        'nav_text' => (string)($raw['nav_text'] ?? '') !== '' ? (string)$raw['nav_text'] : '友链',
        'show_sidebar' => (int)($raw['show_sidebar'] ?? 1) === 1,
        'show_nav' => (int)($raw['show_nav'] ?? 1) === 1,
    ];
}

// 全部链接（排序：sort 升序、id 兜底），请求级缓存避免同页多次查询。
function friend_items(): array
{
    return $GLOBALS['__friend_items'] ??= all('SELECT * FROM plugin_friend_links ORDER BY sort ASC, id ASC');
}

function friend_valid_url(string $url): bool
{
    if ($url === '' || mb_strlen($url) > 250) return false;
    return filter_var($url, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $url) === 1;
}

// 图标地址：自定义优先；未设置时按对方站点协议拼 /favicon.ico（客户端加载失败由 onerror 退回首字母）。
function friend_icon_url(array $item): string
{
    $icon = trim((string)($item['icon'] ?? ''));
    if ($icon !== '' && friend_valid_url($icon)) return $icon;
    $parts = parse_url((string)($item['url'] ?? ''));
    $host = (string)($parts['host'] ?? '');
    if ($host === '') return '';
    $scheme = (($parts['scheme'] ?? '') === 'http') ? 'http' : 'https';
    return $scheme . '://' . $host . '/favicon.ico';
}

// 图标块：首字母垫底 + 图片图层（图片 404 时被移除，露出首字母）。
function friend_icon_html(array $item, string $class = 'friend-ico'): string
{
    $name = (string)($item['name'] ?? '');
    $letter = mb_strtoupper(mb_substr($name !== '' ? $name : '链', 0, 1, 'UTF-8'), 'UTF-8');
    $src = friend_icon_url($item);
    $img = $src !== '' ? '<img src="' . h($src) . '" alt="" loading="lazy" onerror="this.remove()">' : '';
    return '<span class="' . h($class) . '" aria-hidden="true"><span class="friend-letter">' . h($letter) . '</span>' . $img . '</span>';
}

function friend_item_html(array $item): string
{
    $name = (string)($item['name'] ?? '');
    return '<a class="friend-link" href="' . h((string)($item['url'] ?? '')) . '" target="_blank" rel="noopener nofollow" title="' . h($name) . '">'
        . friend_icon_html($item) . '<span class="friend-name">' . h($name) . '</span></a>';
}

// 侧栏卡片（独立页面 ?a=links 上不再重复展示）。
function friend_sidebar(string $value, array $ctx): string
{
    $cfg = friend_config();
    if (!$cfg['show_sidebar']) return $value;
    if ((string)($_GET['a'] ?? '') === 'links') return $value;
    $items = friend_items();
    if (!$items) return $value;
    $links = '';
    foreach ($items as $it) $links .= friend_item_html($it);
    return $value . '<div class="card"><div class="card-title">' . h($cfg['title']) . '</div><div class="friend-grid">' . $links . '</div></div>';
}

// 顶部导航入口。
function friend_nav(array $links, array $ctx): array
{
    $cfg = friend_config();
    if (!$cfg['show_nav'] || !friend_items()) return $links;
    $links[] = ['url' => route_url('links'), 'text' => $cfg['nav_text']];
    return $links;
}

// 独立页面 ?a=links：整页链接墙。
function friend_page(array $plugin): void
{
    $cfg = friend_config();
    $items = friend_items();
    $body = '<div class="card" style="padding:14px 20px;margin-bottom:16px"><strong>' . h($cfg['title']) . '</strong> <span class="badge">' . count($items) . ' 个</span></div>';
    if ($items) {
        $links = '';
        foreach ($items as $it) $links .= friend_item_html($it);
        $body .= '<div class="card"><div class="friend-page-grid">' . $links . '</div></div>';
    } else {
        $body .= '<div class="card"><p style="color:var(--text-muted);margin:0">还没有添加友情链接。</p></div>';
    }
    page($cfg['title'], shell_html($body, sidebar_html()));
}

// 后台管理页：POST 自处理（设置 / 新增 / 更新 / 删除），渲染列表 + 表单。
function friend_admin(array $plugin): string
{
    $back = admin_url(['tab' => 'plugins', 'view' => 'friend']);
    if (is_post_request()) {
        $faction = (string)($_POST['faction'] ?? '');
        if ($faction === 'settings') {
            plugin_save_config('friend', [
                'title' => post('title', 40) ?: '友情链接',
                'nav_text' => post('nav_text', 20) ?: '友链',
                'show_sidebar' => (int)($_POST['show_sidebar'] ?? 0) === 1 ? 1 : 0,
                'show_nav' => (int)($_POST['show_nav'] ?? 0) === 1 ? 1 : 0,
            ]);
            set_flash('设置已保存');
            go($back);
        }
        if ($faction === 'add' || $faction === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $name = post('name', 60);
            $url = post('url', 250);
            $icon = post('icon', 250);
            $sort = (int)($_POST['sort'] ?? 0);
            if ($name === '') {
                set_flash('名称不能为空', 'error');
                go($back);
            }
            if (!friend_valid_url($url) || ($icon !== '' && !friend_valid_url($icon))) {
                set_flash('链接与图标必须是 http/https 地址', 'error');
                go($back);
            }
            if ($faction === 'add') {
                q('INSERT INTO plugin_friend_links(name,url,icon,sort,created_at) VALUES(?,?,?,?,?)', [$name, $url, $icon, $sort, now()]);
                set_flash('链接已添加');
            } else {
                if ($id <= 0 || !one('SELECT id FROM plugin_friend_links WHERE id=?', [$id])) {
                    set_flash('链接不存在', 'error');
                    go($back);
                }
                q('UPDATE plugin_friend_links SET name=?,url=?,icon=?,sort=? WHERE id=?', [$name, $url, $icon, $sort, $id]);
                set_flash('链接已更新');
            }
            go($back);
        }
        if ($faction === 'delete') {
            q('DELETE FROM plugin_friend_links WHERE id=?', [(int)($_POST['id'] ?? 0)]);
            set_flash('链接已删除');
            go($back);
        }
    }

    $cfg = friend_config();
    $items = friend_items();
    $edit_id = (int)($_GET['edit'] ?? 0);
    $edit = $edit_id > 0 ? one('SELECT * FROM plugin_friend_links WHERE id=?', [$edit_id]) : null;

    // 1) 链接列表。
    $html = '<div class="card-title" style="font-size:var(--font-size-md)">链接列表（' . count($items) . '）</div>';
    if ($items) {
        foreach ($items as $it) {
            $html .= '<div class="friend-admin-row">'
                . friend_icon_html($it, 'friend-admin-ico')
                . '<div class="friend-admin-main"><strong>' . h((string)$it['name']) . '</strong>'
                . '<a href="' . h((string)$it['url']) . '" target="_blank" rel="noopener nofollow">' . h((string)$it['url']) . '</a></div>'
                . '<span style="color:var(--text-subtle);font-size:var(--font-size-xs)">#' . (int)$it['sort'] . '</span>'
                . '<a class="btn sm ghost" href="' . h(admin_url(['tab' => 'plugins', 'view' => 'friend', 'edit' => (int)$it['id']])) . '">编辑</a>'
                . post_action_form($back, '删除', ['admin_action' => 'noop', 'faction' => 'delete', 'id' => (int)$it['id']], 'btn sm danger', '删除友情链接「' . (string)$it['name'] . '」？')
                . '</div>';
        }
    } else {
        $html .= '<p style="color:var(--text-muted);font-size:var(--font-size-sm);margin:0">还没有添加链接，使用下方表单添加第一条。</p>';
    }

    // 2) 新增 / 编辑表单。
    $html .= '<div class="card-title" style="font-size:var(--font-size-md);margin-top:22px">' . ($edit ? '编辑链接：' . h((string)$edit['name']) : '添加链接') . '</div>'
        . '<form method="post">' . form_token()
        . '<input type="hidden" name="admin_action" value="noop">'
        . '<input type="hidden" name="faction" value="' . ($edit ? 'update' : 'add') . '">'
        . ($edit ? '<input type="hidden" name="id" value="' . (int)$edit['id'] . '">' : '')
        . '<div class="form-grid-2">'
        . input('名称', 'name', (string)($edit['name'] ?? ''), 'text', true)
        . input('链接', 'url', (string)($edit['url'] ?? ''), 'url', true, 'http/https 地址')
        . '</div><div class="form-grid-2">'
        . input('图标地址', 'icon', (string)($edit['icon'] ?? ''), 'url', false, '留空自动取对方站点 favicon')
        . input('排序', 'sort', (string)(int)($edit['sort'] ?? 0), 'number', false, '数字越小越靠前')
        . '</div>'
        . '<button class="btn" type="submit">' . ($edit ? '保存修改' : '添加链接') . '</button>'
        . ($edit ? ' <a class="btn sm ghost" href="' . h($back) . '">取消编辑</a>' : '')
        . '</form>';

    // 3) 展示设置。
    $html .= '<div class="card-title" style="font-size:var(--font-size-md);margin-top:22px">展示设置</div>'
        . '<form method="post">' . form_token()
        . '<input type="hidden" name="admin_action" value="noop">'
        . '<input type="hidden" name="faction" value="settings">'
        . '<div class="form-grid-2">'
        . input('卡片标题', 'title', $cfg['title'], 'text', false, '侧栏与独立页面的标题')
        . input('导航文字', 'nav_text', $cfg['nav_text'], 'text', false, '顶部导航里的入口文字')
        . '</div>'
        . checkbox('侧栏卡片显示', 'show_sidebar', $cfg['show_sidebar'], '在右侧栏展示友情链接卡片')
        . checkbox('顶部导航 + 独立页面', 'show_nav', $cfg['show_nav'], '导航加入口，页面地址 a=links（伪静态 /links）')
        . '<button class="btn" type="submit">保存设置</button></form>';

    return $html;
}

function friend_css(): string
{
    return <<<'CSS'
.friend-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.friend-page-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}
.friend-link{display:flex;align-items:center;gap:8px;padding:7px 10px;border:1px solid var(--border);border-radius:calc(var(--radius) - 4px);background:var(--card);color:var(--foreground);text-decoration:none;font-size:var(--font-size-sm);min-width:0;transition:border-color .15s,color .15s}
.friend-link:hover{border-color:var(--primary);color:var(--primary)}
.friend-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.friend-ico,.friend-admin-ico{position:relative;flex:none;display:inline-grid;place-items:center;border-radius:5px;background:var(--muted);overflow:hidden}
.friend-ico{width:20px;height:20px}
.friend-admin-ico{width:26px;height:26px;border-radius:6px}
.friend-ico img,.friend-admin-ico img{grid-area:1/1;width:100%;height:100%;object-fit:cover;background:var(--muted)}
.friend-ico .friend-letter,.friend-admin-ico .friend-letter{grid-area:1/1;font-size:11px;font-weight:700;color:var(--muted-foreground);line-height:1}
.friend-admin-row{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--border);flex-wrap:wrap}
.friend-admin-row:last-of-type{border-bottom:0}
.friend-admin-main{min-width:0;flex:1;display:grid;gap:2px}
.friend-admin-main strong{font-size:var(--font-size-sm)}
.friend-admin-main a{color:var(--muted-foreground);font-size:var(--font-size-xs);text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.friend-admin-main a:hover{color:var(--foreground)}
CSS;
}

function friend_install(array $plugin): void
{
    $t = app_db_types();
    app_db_create_table('plugin_friend_links', "id {$t['id']},name {$t['string']} NOT NULL,url {$t['string']} NOT NULL,icon {$t['string']} NOT NULL,sort {$t['uint']} NOT NULL,created_at {$t['uint']} NOT NULL");
    app_db_create_index('idx_friend_sort', 'plugin_friend_links (sort)');
}

function friend_uninstall(array $plugin, bool $keep_data = true): void
{
    if (!$keep_data) {
        app_db_drop_index('idx_friend_sort', 'plugin_friend_links');
        app_db_drop_table('plugin_friend_links');
    }
}

return [
    'id' => 'friend',
    'name' => '友情链接',
    'version' => '1.0.0',
    'description' => '管理友情链接并在侧栏卡片与独立页面（a=links）展示；图标自动取对方站点 favicon，可逐条自定义。',
    'author' => 'Mono',
    'assets' => ['css' => 'friend_css'],
    'hooks' => [
        'sidebar.stack' => 'friend_sidebar',
        'nav.menu_links' => 'friend_nav',
    ],
    'routes' => ['links' => 'friend_page'],
    'admin_tabs' => ['friend' => 'friend_admin'],
    'install' => 'friend_install',
    'uninstall' => 'friend_uninstall',
];
