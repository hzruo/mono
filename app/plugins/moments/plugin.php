<?php
if (!defined('APP_ROOT')) exit;

/**
 * 动态插件（moments）。
 *
 * 微博式短内容（朋友圈风格）信息流：
 * - 导航新增「动态」入口（nav.menu_links 每页一次，非循环）。
 * - 页面顶部快捷发布框：文字（≤1000 字）+ 最多 9 张图片；默认需登录，可在后台关闭后允许游客填昵称发布。
 * - 点赞与评论复用配套插件：「文章点赞」启用时动态可点赞（身份语义一致：登录用 uid、游客用 IP 指纹）；
 *   「评论」启用时动态可评论，并继承其「需登录 / 先审后显」配置（管理员免审）。
 * - 图片存储 DATA_DIR/plugins/moments/，服务端命名 moment-<12hex>.<ext>，仅经输出路由访问（不暴露路径）。
 * - 后台「插件 → 动态」配置页：发布权限开关 + 待审评论列表（通过 / 删除）。
 *
 * 性能：信息流一次取数，点赞数 / 已赞集合 / 评论 / 作者均为 IN 批量预取，渲染循环内零查询。
 * 安全：全部输出 h() 转义；POST 全 CSRF；上传 ≤5MB 且 getimagesize 白名单；删除需管理员；
 * 所有回调内 try/catch，避免异常导致插件被核心自动停用。
 */

// --- 配置与权限 ---
function moments_config(): array
{
    $raw = plugin_config('moments', []);
    return [
        'publish_require_login' => (int)($raw['publish_require_login'] ?? 1) === 1,
    ];
}

// 当前请求是否具备发布 / 上传权限（登录用户恒可；游客视配置）。
function moments_can_publish(array $cfg): bool
{
    return !$cfg['publish_require_login'] || me() !== null;
}

// 点赞插件是否启用（跨插件不调用其函数，仅做存在性判断）。
function moments_like_enabled(): bool
{
    return isset(plugins()['like']);
}

// 评论插件是否启用。
function moments_comment_enabled(): bool
{
    return isset(plugins()['comment']);
}

// 继承评论插件的配置（跨插件不做函数调用，直读其持久化配置）。
function moments_comment_cfg(): array
{
    $raw = plugin_config('comment', []);
    return [
        'require_login' => (int)($raw['require_login'] ?? 0) === 1,
        'moderate' => (int)($raw['moderate'] ?? 0) === 1,
    ];
}

// --- 身份 ---
// 点赞身份键：与文章点赞插件语义一致（登录 u{id}；游客 ip + IP|UA 指纹前 16 位）。
function moments_user_key(): string
{
    $u = uid();
    if ($u > 0) return 'u' . $u;
    return 'ip' . substr(md5((string)($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 16);
}

// --- 目录与文件 ---
function moments_dir(): string { return DATA_DIR . '/plugins/moments'; }

function moments_mimes(): array
{
    return ['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
}

// 图片文件名白名单（防路径穿越），格式与服务端生成一致。
function moments_valid_file(string $name): bool
{
    return preg_match('/^moment-[0-9a-f]{12}\.(png|jpg|gif|webp)$/', $name) === 1;
}

function moments_mime(string $name): string
{
    return moments_mimes()[strtolower((string)pathinfo($name, PATHINFO_EXTENSION))] ?? '';
}

function moments_img_url(string $name): string { return route_url('moments_img', ['f' => $name]); }

// 过滤前端提交的图片名列表：白名单 + 文件存在 + 去重 + 上限 9 张。
function moments_filter_images(mixed $raw): array
{
    $list = is_array($raw) ? $raw : (is_string($raw) ? plugin_json_decode($raw, []) : []);
    $out = [];
    foreach ((array)$list as $name) {
        if (!is_string($name) || !moments_valid_file($name)) continue;
        if (!is_file(moments_dir() . '/' . $name)) continue;
        if (in_array($name, $out, true)) continue;
        $out[] = $name;
        if (count($out) >= 9) break;
    }
    return $out;
}

// 尽力删除本地图片文件（失败不抛出）。
function moments_delete_files(array $names): void
{
    foreach ($names as $name) {
        if (!is_string($name) || !moments_valid_file($name)) continue;
        $file = moments_dir() . '/' . $name;
        if (is_file($file)) @unlink($file);
    }
}

// --- 建表 / 卸载 ---
function moments_install(array $plugin): void
{
    $t = app_db_types();
    app_db_create_table('plugin_moments_moments', "id {$t['id']},user_id {$t['uint']} NOT NULL,guest_name {$t['string']},content {$t['text']} NOT NULL,images {$t['text']} NOT NULL,created_at {$t['uint']} NOT NULL");
    app_db_create_index('idx_moments_created', 'plugin_moments_moments (created_at)');
    app_db_create_table('plugin_moments_likes', "id {$t['id']},moment_id {$t['uint']} NOT NULL,user_key {$t['key']} NOT NULL,created_at {$t['uint']} NOT NULL,UNIQUE (moment_id,user_key)");
    app_db_create_index('idx_moments_likes_moment', 'plugin_moments_likes (moment_id)');
    app_db_create_table('plugin_moments_comments', "id {$t['id']},moment_id {$t['uint']} NOT NULL,author {$t['string']} NOT NULL,email {$t['string']},content {$t['text']} NOT NULL,status {$t['uint']} NOT NULL DEFAULT 1,created_at {$t['uint']} NOT NULL");
    app_db_create_index('idx_moments_comments_moment', 'plugin_moments_comments (moment_id,status,created_at)');
}

function moments_uninstall(array $plugin, bool $keep_data = true): void
{
    if ($keep_data) return;
    app_db_drop_index('idx_moments_created', 'plugin_moments_moments');
    app_db_drop_table('plugin_moments_moments');
    app_db_drop_index('idx_moments_likes_moment', 'plugin_moments_likes');
    app_db_drop_table('plugin_moments_likes');
    app_db_drop_index('idx_moments_comments_moment', 'plugin_moments_comments');
    app_db_drop_table('plugin_moments_comments');
    // 兜底清理图片目录中遗留的文件。
    foreach (glob(moments_dir() . '/moment-*') ?: [] as $file) {
        if (is_file($file) && moments_valid_file(basename($file))) @unlink($file);
    }
}

// --- 导航 ---
function moments_nav_link(array $links, array $ctx): array
{
    try {
        $links[] = ['url' => route_url('moments'), 'text' => '动态'];
    } catch (\Throwable $e) {
        error_log('[Mono moments] nav: ' . $e->getMessage());
    }
    return $links;
}

// --- 图片上传（发布框 AJAX）---
function moments_upload_route(array $plugin): void
{
    require_post();
    try {
        $cfg = moments_config();
        if (!moments_can_publish($cfg)) json_response(['ok' => 0, 'message' => '请先登录后再上传图片']);
        $f = $_FILES['file'] ?? null;
        if (!is_array($f) || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) json_response(['ok' => 0, 'message' => '未收到上传文件']);
        if ((int)$f['error'] !== UPLOAD_ERR_OK) json_response(['ok' => 0, 'message' => '上传失败（错误码 ' . (int)$f['error'] . '）']);
        if ((int)($f['size'] ?? 0) > 5 * 1024 * 1024) json_response(['ok' => 0, 'message' => '图片不能超过 5MB']);
        $tmp = (string)($f['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) json_response(['ok' => 0, 'message' => '非法的上传文件']);
        $info = @getimagesize($tmp);
        $ext = $info === false ? false : array_search((string)$info['mime'], moments_mimes(), true);
        if ($ext === false) json_response(['ok' => 0, 'message' => '仅支持 PNG / JPG / GIF / WebP 格式图片']);

        $dir = moments_dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) json_response(['ok' => 0, 'message' => '存储目录创建失败']);
        $name = 'moment-' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file($tmp, $dir . '/' . $name)) json_response(['ok' => 0, 'message' => '图片保存失败']);
        json_response(['ok' => 1, 'file' => $name, 'url' => moments_img_url($name)]);
    } catch (\Throwable $e) {
        error_log('[Mono moments] upload: ' . $e->getMessage());
        json_response(['ok' => 0, 'message' => '上传失败，请查看站点错误日志']);
    }
}

// --- 图片输出（不暴露真实路径）---
function moments_img_route(array $plugin): void
{
    try {
        $name = (string)($_GET['f'] ?? '');
        $file = moments_valid_file($name) ? moments_dir() . '/' . $name : '';
        if ($file === '' || !is_file($file)) err('图片不存在', 404);
        header('Content-Type: ' . moments_mime($name));
        header('Content-Length: ' . (string)filesize($file));
        header('Cache-Control: public, max-age=86400');
        readfile($file);
        exit;
    } catch (\Throwable $e) {
        error_log('[Mono moments] img: ' . $e->getMessage());
        err('图片不存在', 404);
    }
}

// --- 发布 ---
function moments_publish_route(array $plugin): void
{
    require_post();
    $back = route_url('moments');
    try {
        $cfg = moments_config();
        $me = me();
        if (!moments_can_publish($cfg)) {
            set_flash('请先登录后发布动态', 'error');
            go(route_url('login'));
        }
        $content = trim((string)($_POST['content'] ?? ''));
        if (mb_strlen($content) > 1000) {
            set_flash('动态内容不能超过 1000 字', 'error');
            go($back);
        }
        $images = moments_filter_images($_POST['images'] ?? '');
        if ($content === '' && !$images) {
            set_flash('写点什么或添加一张图片吧', 'error');
            go($back);
        }
        $guest_name = '';
        if (!$me) {
            $guest_name = post('guest_name', 20);
            if ($guest_name === '') {
                set_flash('请填写昵称（1-20 字）', 'error');
                go($back);
            }
        }
        q('INSERT INTO plugin_moments_moments(user_id,guest_name,content,images,created_at) VALUES(?,?,?,?,?)',
            [$me ? (int)$me['id'] : 0, $guest_name, $content, plugin_json_encode($images), now()]);
        set_flash('动态已发布');
        go($back);
    } catch (\Throwable $e) {
        error_log('[Mono moments] publish: ' . $e->getMessage());
        set_flash('发布失败，请稍后重试', 'error');
        go($back);
    }
}

// --- 删除（管理员，级联图片 / 点赞 / 评论）---
function moments_delete_route(array $plugin): void
{
    need_admin();
    require_post();
    $back = route_url('moments');
    try {
        $id = (int)($_POST['id'] ?? 0);
        $page = max(1, (int)($_POST['page'] ?? 1));
        if ($page > 1) $back = route_url('moments', ['page' => $page]);
        $moment = one('SELECT * FROM plugin_moments_moments WHERE id=?', [$id]);
        if (!$moment) err('动态不存在', 404);
        // 先事务内删库（保证点赞 / 评论 / 动态级联一致），再删物理文件；文件删除失败仅残留孤儿文件，不影响页面。
        tx(function () use ($id): void {
            q('DELETE FROM plugin_moments_likes WHERE moment_id=?', [$id]);
            q('DELETE FROM plugin_moments_comments WHERE moment_id=?', [$id]);
            q('DELETE FROM plugin_moments_moments WHERE id=?', [$id]);
        });
        moments_delete_files((array)plugin_json_decode((string)$moment['images'], []));
        set_flash('动态已删除');
        go($back);
    } catch (\Throwable $e) {
        error_log('[Mono moments] delete: ' . $e->getMessage());
        set_flash('删除失败，请稍后重试', 'error');
        go($back);
    }
}

// --- 点赞（仅 like 插件启用时可访问）---
function moments_like_route(array $plugin): void
{
    require_post();
    if (!moments_like_enabled()) err('点赞功能未开启', 404);
    $id = (int)($_POST['id'] ?? 0);
    $ajax = ajax_request();
    $back = route_url('moments') . '#moment-' . $id;
    try {
        $moment = one('SELECT id FROM plugin_moments_moments WHERE id=?', [$id]);
        if (!$moment) {
            if ($ajax) json_response(['ok' => 0, 'message' => '动态不存在']);
            err('动态不存在', 404);
        }
        $key = moments_user_key();
        $exists = one('SELECT id FROM plugin_moments_likes WHERE moment_id=? AND user_key=?', [$id, $key]);
        if ($exists) {
            q('DELETE FROM plugin_moments_likes WHERE moment_id=? AND user_key=?', [$id, $key]);
            $liked = false;
        } else {
            app_db_insert_ignore('plugin_moments_likes', ['moment_id' => $id, 'user_key' => $key, 'created_at' => now()], ['moment_id', 'user_key']);
            $liked = true;
        }
        $count = (int)val('SELECT COUNT(*) FROM plugin_moments_likes WHERE moment_id=?', [$id]);
        if ($ajax) json_response(['ok' => 1, 'liked' => $liked, 'count' => $count]);
        set_flash($liked ? '已点赞' : '已取消点赞');
        go($back);
    } catch (\Throwable $e) {
        error_log('[Mono moments] like: ' . $e->getMessage());
        if ($ajax) json_response(['ok' => 0, 'message' => '操作失败，请稍后重试']);
        set_flash('操作失败，请稍后重试', 'error');
        go($back);
    }
}

// --- 评论（仅 comment 插件启用时可访问，继承其配置）---
function moments_comment_route(array $plugin): void
{
    require_post();
    if (!moments_comment_enabled()) err('评论功能未开启', 404);
    $id = (int)($_POST['id'] ?? 0);
    $back = route_url('moments') . '#moment-' . $id;
    try {
        $moment = one('SELECT id FROM plugin_moments_moments WHERE id=?', [$id]);
        if (!$moment) {
            set_flash('动态不存在', 'error');
            go(route_url('moments'));
        }
        if (setting('allow_comment', '1') !== '1') {
            set_flash('评论已关闭', 'error');
            go($back);
        }
        $cfg = moments_comment_cfg();
        $me = me();
        if ($cfg['require_login'] && !$me) {
            set_flash('请先登录后评论', 'error');
            go(route_url('login'));
        }
        $content = trim((string)($_POST['content'] ?? ''));
        if ($content === '') {
            set_flash('评论内容不能为空', 'error');
            go($back);
        }
        if (mb_strlen($content) > 1000) {
            set_flash('评论内容过长', 'error');
            go($back);
        }
        if ($me) {
            $author = (string)$me['nickname'];
            $email = (string)$me['email'];
        } else {
            $author = post('author', 40);
            $email = post('email', 150);
            if ($author === '') {
                set_flash('请填写昵称', 'error');
                go($back);
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                set_flash('邮箱格式不正确', 'error');
                go($back);
            }
        }
        // 60 秒重复内容抑制（同昵称 + 同内容）。
        $dup = one('SELECT id FROM plugin_moments_comments WHERE moment_id=? AND author=? AND content=? AND created_at>? LIMIT 1', [$id, $author, $content, now() - 60]);
        if ($dup) {
            set_flash('请勿重复提交相同内容', 'error');
            go($back);
        }
        $status = $cfg['moderate'] && !is_admin() ? 0 : 1;
        q('INSERT INTO plugin_moments_comments(moment_id,author,email,content,status,created_at) VALUES(?,?,?,?,?,?)',
            [$id, $author, $email, $content, $status, now()]);
        set_flash($status === 1 ? '评论发表成功' : '评论已提交，等待审核');
        go($back);
    } catch (\Throwable $e) {
        error_log('[Mono moments] comment: ' . $e->getMessage());
        set_flash('评论失败，请稍后重试', 'error');
        go($back);
    }
}

// --- 动态页（发布框 + 信息流）---
function moments_page_route(array $plugin): void
{
    try {
        page('动态', shell_html(moments_publish_box() . moments_feed(), sidebar_html()));
    } catch (\Throwable $e) {
        error_log('[Mono moments] page: ' . $e->getMessage());
        page('动态', shell_html('<div class="card"><p class="moments-empty">动态加载失败，请稍后重试。</p></div>', sidebar_html()));
    }
}

// 顶部快捷发布框：未登录且要求登录时显示提示；游客可发布时显示昵称输入。
function moments_publish_box(): string
{
    $cfg = moments_config();
    $me = me();
    if (!moments_can_publish($cfg)) {
        return '<div class="card moments-publish"><p class="moments-login-hint">发布动态需登录，<a href="' . h(route_url('login')) . '">去登录</a></p></div>';
    }
    $html = '<form class="card moments-publish" method="post" action="' . h(route_url('moments_publish')) . '" data-moments-publish>' . form_token();
    if (!$me) {
        $html .= '<input class="moments-name-input" type="text" name="guest_name" maxlength="20" placeholder="你的昵称（必填，1-20 字）" required>';
    }
    $html .= '<textarea name="content" rows="3" maxlength="1000" placeholder="此刻的想法…"></textarea>'
        . '<input type="hidden" name="images" value="">'
        . '<div class="moments-previews" data-moments-previews hidden></div>'
        . '<div class="moments-publish-bar">'
        . '<button type="button" class="btn ghost sm" data-moments-pick>添加图片</button>'
        . '<span class="moments-pick-hint">最多 9 张，单张 ≤5MB</span>'
        . '<button class="btn" type="submit">发布</button>'
        . '</div>'
        . '<input type="file" accept="image/png,image/jpeg,image/gif,image/webp" multiple hidden data-moments-file'
        . ' data-url="' . h(route_url('moments_upload')) . '" data-csrf="' . h(csrf_token()) . '">'
        . '</form>';
    return $html;
}

// 信息流：一次取数 + 点赞数 / 已赞集合 / 评论 / 作者四组 IN 批量预取，渲染内零查询。
function moments_feed(): string
{
    $page = current_page();
    $per = 10;
    $total = (int)val('SELECT COUNT(*) FROM plugin_moments_moments');
    if ($total < 1) return '<div class="card"><p class="moments-empty">还没有动态，来发布第一条吧。</p></div>';
    $rows = all('SELECT * FROM plugin_moments_moments ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
    if (!$rows) return '<div class="card"><p class="moments-empty">没有更多动态了。</p></div>';

    $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
    $marks = sql_marks(count($ids));
    $like_on = moments_like_enabled();
    $comment_on = moments_comment_enabled() && setting('allow_comment', '1') === '1';

    $like_counts = [];
    $liked_ids = [];
    if ($like_on) {
        foreach (all("SELECT moment_id, COUNT(*) AS cnt FROM plugin_moments_likes WHERE moment_id IN ($marks) GROUP BY moment_id", $ids) as $r) {
            $like_counts[(int)$r['moment_id']] = (int)$r['cnt'];
        }
        foreach (all("SELECT moment_id FROM plugin_moments_likes WHERE user_key=? AND moment_id IN ($marks)", array_merge([moments_user_key()], $ids)) as $r) {
            $liked_ids[(int)$r['moment_id']] = true;
        }
    }
    $comments = [];
    if ($comment_on) {
        foreach (all("SELECT * FROM plugin_moments_comments WHERE moment_id IN ($marks) AND status=1 ORDER BY id ASC", $ids) as $c) {
            $comments[(int)$c['moment_id']][] = $c;
        }
    }
    $author_ids = [];
    foreach ($rows as $r) {
        $author_id = (int)$r['user_id'];
        if ($author_id > 0) $author_ids[$author_id] = true;
    }
    $users = [];
    if ($author_ids) {
        $uids = array_keys($author_ids);
        foreach (all('SELECT id,nickname,email,avatar FROM app_users WHERE id IN (' . sql_marks(count($uids)) . ')', $uids) as $u) {
            $users[(int)$u['id']] = $u;
        }
    }

    $ctx = [
        'users' => $users,
        'like_on' => $like_on,
        'like_counts' => $like_counts,
        'liked_ids' => $liked_ids,
        'comment_on' => $comment_on,
        'comment_cfg' => moments_comment_cfg(),
        'comments' => $comments,
        'page' => $page,
    ];
    // 信息流按日期分组：左列时间线显示「月日 + 年」，右列是当天卡片。
    $html = '';
    $open_day = '';
    foreach ($rows as $r) {
        $ts = (int)$r['created_at'];
        $day = date('Y-m-d', $ts);
        if ($day !== $open_day) {
            if ($open_day !== '') $html .= '</div></section>';
            $open_day = $day;
            $html .= '<section class="moments-group"><div class="moments-tline">'
                . '<span class="moments-tline-day">' . h(date('n月j日', $ts)) . '</span>'
                . '<span class="moments-tline-year">' . h(date('Y', $ts)) . '</span>'
                . '</div><div class="moments-group-body">';
        }
        $html .= moments_card($r, $ctx);
    }
    if ($open_day !== '') $html .= '</div></section>';
    $html .= paginate($total, $page, $per, static fn(int $p): string => route_url('moments', $p > 1 ? ['page' => $p] : []));
    return $html;
}

// 单条动态卡片。
function moments_card(array $r, array $ctx): string
{
    $id = (int)$r['id'];
    $author_id = (int)$r['user_id'];
    $user = $author_id > 0 ? ($ctx['users'][$author_id] ?? null) : null;
    if ($user) {
        $name = (string)$user['nickname'];
        $avatar = user_avatar_url($user, 84);
    } else {
        $name = trim((string)($r['guest_name'] ?? ''));
        if ($name === '') $name = '访客';
        $avatar = avatar_url($name === '访客' ? 'guest' : $name, 84);
    }
    $ts = (int)$r['created_at'];

    $html = '<article class="card moments-card" id="moment-' . $id . '">';
    $html .= '<div class="moments-head">'
        . '<img class="moments-avatar" src="' . h($avatar) . '" alt="" width="42" height="42">'
        . '<div class="moments-meta"><span class="moments-name">' . h($name) . '</span>'
        . '<span class="moments-time" title="' . h(date('Y-m-d H:i', $ts)) . '">' . h(date('H:i', $ts)) . '</span></div>';
    if (is_admin()) {
        $html .= '<form class="moments-del" method="post" action="' . h(route_url('moments_delete')) . '" data-confirm="删除这条动态？图片、点赞与评论将一并删除。">' . form_token()
            . '<input type="hidden" name="id" value="' . $id . '">'
            . '<input type="hidden" name="page" value="' . (int)$ctx['page'] . '">'
            . '<button class="moments-del-btn" type="submit" title="删除动态">×</button></form>';
    }
    $html .= '</div>';

    $content = trim((string)$r['content']);
    if ($content !== '') $html .= '<div class="moments-body">' . nl2br(h($content)) . '</div>';
    $html .= moments_image_grid((array)plugin_json_decode((string)$r['images'], []));

    if ($ctx['like_on'] || $ctx['comment_on']) {
        $html .= '<div class="moments-actions">';
        if ($ctx['like_on']) {
            $liked = isset($ctx['liked_ids'][$id]);
            $count = (int)($ctx['like_counts'][$id] ?? 0);
            $html .= '<form class="moments-like-form" method="post" action="' . h(route_url('moments_like')) . '">' . form_token()
                . '<input type="hidden" name="id" value="' . $id . '">'
                . '<button class="moments-like-btn' . ($liked ? ' liked' : '') . '" type="submit" title="' . ($liked ? '取消点赞' : '点赞') . '">'
                . '<span class="moments-heart">♥</span><span class="moments-like-count">' . ($count > 0 ? $count : '赞') . '</span></button></form>';
        }
        if ($ctx['comment_on']) {
            $n = count($ctx['comments'][$id] ?? []);
            $html .= '<span class="moments-comment-hint">' . ($n > 0 ? '评论 ' . $n : '评论') . '</span>';
        }
        $html .= '</div>';
    }
    if ($ctx['comment_on']) $html .= moments_comment_block($id, $ctx['comments'][$id] ?? [], $ctx['comment_cfg']);
    return $html . '</article>';
}

// 九宫格图片（点击走灯箱，href 兜底可直接打开）。
function moments_image_grid(array $names): string
{
    $names = array_values(array_filter($names, static fn(mixed $n): bool => is_string($n) && moments_valid_file($n)));
    if (!$names) return '';
    $html = '<div class="moments-imgs n' . count($names) . '">';
    foreach ($names as $name) {
        $url = moments_img_url($name);
        $html .= '<a class="moments-img" href="' . h($url) . '" data-moments-lightbox><img src="' . h($url) . '" alt="" loading="lazy"></a>';
    }
    return $html . '</div>';
}

// 评论区：已审评论平铺展示 + 发表表单（游客字段与需登录提示按 comment 插件配置）。
function moments_comment_block(int $id, array $comments, array $cfg): string
{
    $html = '<div class="moments-comments">';
    foreach ($comments as $c) {
        $cts = (int)$c['created_at'];
        $html .= '<div class="moments-comment">'
            . '<span class="moments-comment-name">' . h((string)$c['author']) . '</span>'
            . '<span class="moments-comment-text">' . nl2br(h((string)$c['content'])) . '</span>'
            . '<span class="moments-comment-time" title="' . h(date('Y-m-d H:i', $cts)) . '">' . h(human_time($cts)) . '</span></div>';
    }
    $me = me();
    if (!$me && $cfg['require_login']) {
        return $html . '<p class="moments-login-inline">评论需登录后发表，<a href="' . h(route_url('login')) . '">去登录</a></p></div>';
    }
    $html .= '<form class="moments-comment-form" method="post" action="' . h(route_url('moments_comment')) . '">' . form_token()
        . '<input type="hidden" name="id" value="' . $id . '">';
    if (!$me) {
        $html .= '<div class="moments-comment-fields">'
            . '<input type="text" name="author" maxlength="40" placeholder="昵称" required>'
            . '<input type="email" name="email" maxlength="150" placeholder="邮箱（可选）">'
            . '</div>';
    }
    $html .= '<div class="moments-comment-input"><textarea name="content" rows="1" maxlength="1000" placeholder="友好评论…" required></textarea>'
        . '<button class="btn sm" type="submit">评论</button></div></form>';
    return $html . '</div>';
}

// --- 后台（plugins&view=moments）：发布权限配置 + 待审评论 ---
function moments_admin(array $plugin): string
{
    try {
        $back = admin_url(['tab' => 'plugins', 'view' => 'moments']);
        if (is_post_request()) {
            $action = (string)($_POST['moments_action'] ?? '');
            if ($action === 'save') {
                plugin_save_config('moments', [
                    'publish_require_login' => (int)($_POST['publish_require_login'] ?? 0) === 1 ? 1 : 0,
                ]);
                set_flash('动态设置已保存');
                go($back);
            }
            if ($action === 'approve') {
                q('UPDATE plugin_moments_comments SET status=1 WHERE id=?', [(int)($_POST['id'] ?? 0)]);
                set_flash('评论已通过');
                go($back);
            }
            if ($action === 'delete_comment') {
                q('DELETE FROM plugin_moments_comments WHERE id=?', [(int)($_POST['id'] ?? 0)]);
                set_flash('评论已删除');
                go($back);
            }
        }
        return moments_admin_html();
    } catch (\Throwable $e) {
        error_log('[Mono moments] admin: ' . $e->getMessage());
        return '<p style="color:var(--danger)">动态后台渲染失败：' . h($e->getMessage()) . '</p>';
    }
}

function moments_admin_html(): string
{
    $cfg = moments_config();
    $html = '<form method="post" style="margin-bottom:16px">' . form_token()
        . '<input type="hidden" name="admin_action" value="noop">'
        . '<input type="hidden" name="moments_action" value="save">'
        . checkbox('发布动态需登录', 'publish_require_login', $cfg['publish_require_login'], '关闭后游客填写昵称即可发布动态与图片')
        . '<button class="btn" type="submit">保存设置</button></form>';
    $html .= '<div class="btn-row" style="margin-bottom:4px"><a class="btn sm ghost" href="' . h(route_url('moments')) . '">查看动态页</a></div>';

    if (!moments_comment_enabled()) {
        return $html . '<div class="note">评论插件未启用：动态页不显示评论区，也没有待审评论。</div>';
    }
    $count = (int)val('SELECT COUNT(*) FROM plugin_moments_comments WHERE status=0');
    $pending = all('SELECT * FROM plugin_moments_comments WHERE status=0 ORDER BY id ASC LIMIT 100');
    $html .= '<div class="moments-admin-title">待审评论（' . $count . '）</div>';
    if (!$pending) {
        return $html . '<p class="moments-empty">暂无待审评论。评论插件开启「先审后显」后，游客评论会进入这里。</p>';
    }
    $moment_ids = [];
    foreach ($pending as $c) $moment_ids[(int)$c['moment_id']] = true;
    $previews = [];
    if ($moment_ids) {
        $idlist = array_keys($moment_ids);
        foreach (all('SELECT id,content FROM plugin_moments_moments WHERE id IN (' . sql_marks(count($idlist)) . ')', $idlist) as $m) {
            $previews[(int)$m['id']] = trim((string)$m['content']);
        }
    }
    $html .= '<table class="list"><thead><tr><th>作者</th><th>内容</th><th>所属动态</th><th>时间</th><th class="actions">操作</th></tr></thead><tbody>';
    foreach ($pending as $c) {
        $mid = (int)$c['moment_id'];
        $preview = $previews[$mid] ?? '';
        $html .= '<tr><td>' . h((string)$c['author']) . '</td>'
            . '<td style="max-width:280px">' . h(cut((string)$c['content'], 40)) . '</td>'
            . '<td><a href="' . h(route_url('moments') . '#moment-' . $mid) . '">' . h($preview !== '' ? cut($preview, 16) : '动态 #' . $mid) . '</a></td>'
            . '<td style="color:var(--text-subtle)">' . h(date('Y-m-d H:i', (int)$c['created_at'])) . '</td>'
            . '<td class="actions"><div class="btn-row" style="justify-content:flex-end">'
            . '<form method="post" style="display:inline">' . form_token() . '<input type="hidden" name="admin_action" value="noop"><input type="hidden" name="moments_action" value="approve"><input type="hidden" name="id" value="' . (int)$c['id'] . '"><button class="btn sm" type="submit">通过</button></form>'
            . '<form method="post" style="display:inline" data-confirm="删除这条评论？">' . form_token() . '<input type="hidden" name="admin_action" value="noop"><input type="hidden" name="moments_action" value="delete_comment"><input type="hidden" name="id" value="' . (int)$c['id'] . '"><button class="btn sm danger" type="submit">删除</button></form>'
            . '</div></td></tr>';
    }
    $html .= '</tbody></table>';
    if ($count > count($pending)) $html .= '<p class="moments-empty">仅显示最早的 100 条，处理后可刷新继续。</p>';
    return $html;
}

// --- 资源（合并进 plugins.css / plugins.js）---
function moments_css(): string
{
    return <<<'CSS'
.moments-publish{margin-bottom:16px}
.moments-publish textarea{display:block;width:100%;min-height:72px;padding:10px 12px;border:1px solid var(--input);border-radius:10px;background:var(--background);color:var(--foreground);font-size:var(--font-size-base);line-height:1.6;resize:vertical;outline:none}
.moments-publish textarea:focus{border-color:var(--ring);box-shadow:0 0 0 3px color-mix(in oklab,var(--ring) 22%,transparent)}
.moments-name-input{display:block;width:100%;max-width:260px;margin-bottom:8px;padding:8px 12px;border:1px solid var(--input);border-radius:8px;background:var(--background);color:var(--foreground);outline:none}
.moments-publish-bar{display:flex;align-items:center;gap:10px;margin-top:10px}
.moments-pick-hint{margin-right:auto;color:var(--text-subtle);font-size:var(--font-size-xs)}
.moments-previews{display:grid;grid-template-columns:repeat(auto-fill,86px);gap:8px;margin-top:10px}
.moments-preview{position:relative;width:86px;height:86px;border:1px solid var(--line);border-radius:8px;overflow:hidden;background:var(--muted)}
.moments-preview img{display:block;width:100%;height:100%;object-fit:cover}
.moments-preview-loading{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:var(--text-subtle);font-size:var(--font-size-xs)}
.moments-preview-del{position:absolute;top:3px;right:3px;width:20px;height:20px;border:0;border-radius:50%;background:rgba(0,0,0,.55);color:#fff;font-size:13px;line-height:1;cursor:pointer}
.moments-login-hint{margin:0;color:var(--text-muted)}
.moments-login-hint a,.moments-login-inline a{color:var(--brand)}
.moments-group{margin-bottom:2px}
.moments-tline{display:flex;align-items:center;gap:8px;margin:20px 0 10px}
.moments-tline-day{font-size:var(--font-size-md);font-weight:600;white-space:nowrap}
.moments-tline-year{color:var(--text-subtle);font-size:var(--font-size-xs);white-space:nowrap}
.moments-tline::after{content:"";flex:1;height:1px;background:var(--line)}
.moments-card{margin-bottom:14px}
.moments-head{display:flex;align-items:center;gap:10px}
.moments-avatar{width:42px;height:42px;border-radius:50%;background:var(--muted);flex:none}
.moments-meta{display:flex;flex-direction:column;gap:2px;min-width:0}
.moments-name{font-weight:600}
.moments-time{color:var(--text-subtle);font-size:var(--font-size-xs)}
.moments-del{margin-left:auto}
.moments-del-btn{width:26px;height:26px;border:1px solid transparent;border-radius:6px;background:transparent;color:var(--text-subtle);font-size:15px;line-height:1;cursor:pointer}
.moments-del-btn:hover{border-color:var(--line);color:var(--danger)}
.moments-body{margin-top:10px;line-height:1.75;word-break:break-word;overflow-wrap:anywhere}
.moments-imgs{display:grid;grid-template-columns:repeat(3,minmax(0,132px));gap:6px;margin-top:10px}
.moments-imgs.n1{grid-template-columns:minmax(0,240px)}
.moments-imgs.n2,.moments-imgs.n4{grid-template-columns:repeat(2,minmax(0,150px))}
.moments-img{display:block;aspect-ratio:1;border-radius:8px;overflow:hidden;background:var(--muted)}
.moments-img img{display:block;width:100%;height:100%;object-fit:cover;transition:transform .18s}
.moments-img:hover img{transform:scale(1.03)}
.moments-actions{display:flex;align-items:center;gap:14px;margin-top:12px}
.moments-like-form{display:inline}
.moments-like-btn{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border:1px solid var(--line);border-radius:999px;background:var(--panel);color:var(--text-muted);font-size:var(--font-size-sm);cursor:pointer}
.moments-like-btn:hover{border-color:var(--brand);color:var(--brand)}
.moments-like-btn.liked{background:var(--brand-soft);border-color:var(--brand);color:var(--brand);font-weight:600}
.moments-like-btn[disabled]{opacity:.6;cursor:default}
.moments-heart{font-size:13px}
.moments-comment-hint{color:var(--text-subtle);font-size:var(--font-size-sm)}
.moments-comments{margin-top:12px;padding-top:10px;border-top:1px dashed var(--line);display:flex;flex-direction:column;gap:6px}
.moments-comment{line-height:1.6;font-size:var(--font-size-sm);word-break:break-word;overflow-wrap:anywhere}
.moments-comment-name{font-weight:600;margin-right:6px;color:var(--text-muted)}
.moments-comment-time{margin-left:8px;color:var(--text-subtle);font-size:var(--font-size-xs)}
.moments-login-inline{margin:2px 0 0;color:var(--text-subtle);font-size:var(--font-size-sm)}
.moments-comment-form{margin-top:6px}
.moments-comment-fields{display:flex;gap:8px;margin-bottom:8px}
.moments-comment-fields input{flex:1;min-width:0;padding:7px 10px;border:1px solid var(--input);border-radius:8px;background:var(--background);color:var(--foreground);outline:none}
.moments-comment-input{display:flex;gap:8px;align-items:flex-end}
.moments-comment-input textarea{flex:1;min-height:38px;max-height:140px;padding:8px 10px;border:1px solid var(--input);border-radius:8px;background:var(--background);color:var(--foreground);line-height:1.5;resize:vertical;outline:none}
.moments-empty{margin:0;color:var(--text-muted)}
.moments-admin-title{font-weight:600;margin:18px 0 8px}
.moments-lightbox{position:fixed;inset:0;z-index:99;display:none;align-items:center;justify-content:center;padding:24px;background:rgba(0,0,0,.82);cursor:zoom-out}
.moments-lightbox.open{display:flex}
.moments-lightbox img{max-width:92vw;max-height:88vh;border-radius:6px}
@media (max-width:640px){.moments-imgs{grid-template-columns:repeat(3,minmax(0,1fr))}.moments-imgs.n1{grid-template-columns:minmax(0,200px)}.moments-imgs.n2,.moments-imgs.n4{grid-template-columns:repeat(2,minmax(0,1fr))}}
CSS;
}

function moments_js(): string
{
    return <<<'JS'
(function () {
  'use strict';

  // 发布框：选图 → AJAX 上传 → 九宫格预览（可移除）→ 同步 hidden images 字段。
  function bindPublisher() {
    var form = document.querySelector('[data-moments-publish]');
    if (!form) return;
    var pick = form.querySelector('[data-moments-pick]');
    var file = form.querySelector('[data-moments-file]');
    var previews = form.querySelector('[data-moments-previews]');
    var hidden = form.querySelector('input[name="images"]');
    if (!pick || !file || !previews || !hidden) return;
    var names = [];
    var sync = function () {
      hidden.value = JSON.stringify(names);
      if (!names.length) previews.hidden = true;
    };
    pick.addEventListener('click', function () {
      if (names.length >= 9) { alert('最多上传 9 张图片'); return; }
      file.click();
    });
    file.addEventListener('change', function () {
      var list = Array.prototype.slice.call(file.files || []);
      file.value = '';
      list.forEach(function (f) {
        if (names.length >= 9) { alert('最多上传 9 张图片'); return; }
        if (f.size > 5 * 1024 * 1024) { alert('图片不能超过 5MB：' + f.name); return; }
        uploadOne(f);
      });
    });
    function uploadOne(f) {
      var item = document.createElement('div');
      item.className = 'moments-preview';
      var loading = document.createElement('span');
      loading.className = 'moments-preview-loading';
      loading.textContent = '上传中…';
      item.appendChild(loading);
      previews.hidden = false;
      previews.appendChild(item);
      var fd = new FormData();
      fd.append('file', f);
      fd.append('_csrf', file.getAttribute('data-csrf') || '');
      fetch(file.getAttribute('data-url'), {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
      }).then(function (r) { return r.json(); }).then(function (res) {
        if (!res || res.ok !== 1) { item.remove(); alert((res && res.message) || '上传失败，请重试'); return; }
        var img = document.createElement('img');
        img.src = res.url;
        img.alt = '';
        item.appendChild(img);
        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'moments-preview-del';
        del.title = '移除';
        del.textContent = '×';
        del.addEventListener('click', function () {
          names = names.filter(function (x) { return x !== res.file; });
          item.remove();
          sync();
        });
        item.appendChild(del);
        names.push(res.file);
        sync();
      }).catch(function () { item.remove(); alert('网络错误，上传失败，请重试'); });
    }
  }

  // 点赞：拦截提交走 AJAX，原地更新状态与计数。
  function bindLikes() {
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!form || !form.classList || !form.classList.contains('moments-like-form')) return;
      e.preventDefault();
      var btn = form.querySelector('.moments-like-btn');
      var count = form.querySelector('.moments-like-count');
      if (!btn || btn.disabled) return;
      btn.disabled = true;
      fetch(form.action, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(form)
      }).then(function (r) { return r.json(); }).then(function (res) {
        if (!res || res.ok !== 1) { alert((res && res.message) || '操作失败，请重试'); return; }
        if (res.liked) btn.classList.add('liked'); else btn.classList.remove('liked');
        btn.title = res.liked ? '取消点赞' : '点赞';
        if (count) count.textContent = res.count > 0 ? String(res.count) : '赞';
      }).catch(function () { alert('网络错误，操作失败，请重试'); }).then(function () { btn.disabled = false; });
    });
  }

  // 图片灯箱：点击九宫格查看，点击任意处或 Esc 关闭。
  function bindLightbox() {
    var box = null;
    function ensure() {
      if (box) return box;
      box = document.createElement('div');
      box.className = 'moments-lightbox';
      var img = document.createElement('img');
      img.alt = '';
      box.appendChild(img);
      box.addEventListener('click', function () { box.classList.remove('open'); });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape') box.classList.remove('open'); });
      document.body.appendChild(box);
      return box;
    }
    document.addEventListener('click', function (e) {
      var link = e.target && e.target.closest ? e.target.closest('[data-moments-lightbox]') : null;
      if (!link) return;
      e.preventDefault();
      ensure().querySelector('img').src = link.getAttribute('href') || '';
      box.classList.add('open');
    });
  }

  function init() { bindPublisher(); bindLikes(); bindLightbox(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
}());
JS;
}

return [
    'id' => 'moments',
    'name' => '动态',
    'version' => '1.0.0',
    'description' => '微博式动态流：顶部快捷发布（文字 + 最多 9 张图片），朋友圈风格信息流；点赞与评论复用已启用的「文章点赞」「评论」插件，支持发布权限控制与评论审核。',
    'author' => 'Mono',
    'assets' => ['css' => 'moments_css', 'js' => 'moments_js'],
    'hooks' => [
        'nav.menu_links' => 'moments_nav_link',
    ],
    'routes' => [
        'moments' => 'moments_page_route',
        'moments_publish' => 'moments_publish_route',
        'moments_delete' => 'moments_delete_route',
        'moments_like' => 'moments_like_route',
        'moments_comment' => 'moments_comment_route',
        'moments_upload' => 'moments_upload_route',
        'moments_img' => 'moments_img_route',
    ],
    'admin_tabs' => ['moments' => 'moments_admin'],
    'install' => 'moments_install',
    'uninstall' => 'moments_uninstall',
];
