<?php
if (!defined('APP_ROOT')) exit;

/**
 * 点赞插件（like）。
 *
 * 采用「占位符回填」机制解决文章列表的 N+1 查询：
 * 1. 埋点：post.list.after_render 在每篇文章插入唯一占位符 <!--like-{token}-{post_id}-->，循环内零查询。
 * 2. 收集 + 回填：page.before_render（整页一次）收集所有占位符 post_id，用一条 IN(...) 批量取回点赞数，
 *    preg_replace_callback 整页替换一次。
 * 3. 详情页 post.actions：单点渲染，允许一次查询直接输出点赞按钮。
 *
 * 用户身份：登录用户用 uid，游客用 IP 指纹（like_user_key）。
 */

function like_install(array $plugin): void
{
    $t = app_db_types();
    app_db_create_table('plugin_like_likes', "id {$t['id']},post_id {$t['uint']} NOT NULL,user_key {$t['key']} NOT NULL,created_at {$t['uint']} NOT NULL,UNIQUE (post_id,user_key)");
    app_db_create_index('idx_like_post', 'plugin_like_likes (post_id)');
}

function like_uninstall(array $plugin, bool $keep_data = true): void
{
    if (!$keep_data) {
        app_db_drop_index('idx_like_post', 'plugin_like_likes');
        app_db_drop_table('plugin_like_likes');
    }
}

function like_css(): string
{
    return <<<'CSS'
.like-btn{display:inline-flex;align-items:center;gap:6px;padding:5px 14px;border:1px solid var(--line);border-radius:999px;background:var(--panel);color:var(--text-muted);font-size:var(--font-size-sm);cursor:pointer}
.like-btn:hover{border-color:var(--brand);color:var(--brand)}
.like-btn.liked{background:var(--brand-soft);border-color:var(--brand);color:var(--brand);font-weight:600}
.like-inline{display:inline-flex;align-items:center;gap:4px;color:var(--text-subtle);font-size:var(--font-size-xs)}
CSS;
}

// 当前请求的点赞身份键（登录用 uid，游客用 IP 哈希）。
function like_user_key(): string
{
    $u = uid();
    if ($u > 0) return 'u' . $u;
    return 'ip' . substr(md5((string)($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 16);
}

// 本请求内所有占位符共用的随机 token。
function like_token(): string
{
    return $GLOBALS['__like_token'] ??= bin2hex(random_bytes(6));
}

// 列表埋点：只插入占位符并登记 post_id，绝不查库。
function like_list_render(string $value, array $ctx): string
{
    $post_id = (int)($ctx['post']['id'] ?? 0);
    if ($post_id <= 0) return $value;
    $GLOBALS['__like_pending'][$post_id] = true;
    return $value . '<span class="like-inline"><!--like-' . like_token() . '-' . $post_id . '--></span>';
}

// 整页回填：收集占位符 post_id，一次 IN 查询取回点赞数，整页替换一次。
function like_backfill(string $html, array $ctx): string
{
    $token = $GLOBALS['__like_token'] ?? '';
    if ($token === '' || !str_contains($html, '<!--like-')) return $html;
    $ids = array_keys($GLOBALS['__like_pending'] ?? []);
    $counts = [];
    if ($ids) {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $rows = all('SELECT post_id, COUNT(*) AS cnt FROM plugin_like_likes WHERE post_id IN (' . sql_marks(count($ids)) . ') GROUP BY post_id', $ids);
        foreach ($rows as $r) $counts[(int)$r['post_id']] = (int)$r['cnt'];
    }
    $pattern = '/<!--like-' . preg_quote($token, '/') . '-(\d+)-->/';
    return preg_replace_callback($pattern, static function (array $m) use ($counts): string {
        $pid = (int)$m[1];
        $n = $counts[$pid] ?? 0;
        return '♥ ' . $n . ' 赞';
    }, $html) ?? $html;
}

// 详情页点赞按钮（单点渲染，允许一次查询）。
function like_post_action(string $value, array $ctx): string
{
    $post_id = (int)($ctx['post']['id'] ?? 0);
    if ($post_id <= 0) return $value;
    $count = (int)val('SELECT COUNT(*) FROM plugin_like_likes WHERE post_id=?', [$post_id]);
    $liked = (bool)one('SELECT id FROM plugin_like_likes WHERE post_id=? AND user_key=?', [$post_id, like_user_key()]);
    $btn = '<form method="post" action="' . h(route_url('like_toggle')) . '" style="display:inline">' . form_token()
        . '<input type="hidden" name="post_id" value="' . $post_id . '">'
        . '<button type="submit" class="like-btn' . ($liked ? ' liked' : '') . '">♥ ' . ($liked ? '已赞' : '点赞') . ' <span>' . $count . '</span></button></form>';
    return $value . $btn;
}

// 切换点赞状态。
function like_toggle(array $plugin): void
{
    require_post();
    $post_id = (int)($_POST['post_id'] ?? 0);
    $post = row('app_posts', 'id', $post_id);
    if (!$post) err('文章不存在');
    $key = like_user_key();
    $exists = one('SELECT id FROM plugin_like_likes WHERE post_id=? AND user_key=?', [$post_id, $key]);
    if ($exists) {
        q('DELETE FROM plugin_like_likes WHERE post_id=? AND user_key=?', [$post_id, $key]);
        set_flash('已取消点赞');
    } else {
        app_db_insert_ignore('plugin_like_likes', ['post_id' => $post_id, 'user_key' => $key, 'created_at' => now()], ['post_id', 'user_key']);
        set_flash('感谢点赞！');
    }
    go(route_url('post', ['id' => $post_id]));
}

return [
    'id' => 'like',
    'name' => '文章点赞',
    'version' => '1.0.2',
    'description' => '为文章增加点赞功能，列表展示点赞数，详情页可点赞或取消。',
    'author' => 'Mono',
    'assets' => ['css' => 'like_css'],
    'hooks' => [
        'post.list.after_render' => 'like_list_render',
        'page.before_render' => 'like_backfill',
        'post.actions' => 'like_post_action',
    ],
    'routes' => ['like_toggle' => 'like_toggle'],
    'install' => 'like_install',
    'uninstall' => 'like_uninstall',
];
