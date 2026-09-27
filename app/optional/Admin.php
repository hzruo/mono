<?php
declare(strict_types=1);

namespace app\optional;

/**
 * 后台管理。
 *
 * 提供仪表盘、文章管理、分类/标签管理、站点设置与插件页。
 * 所有页面需管理员权限；状态变更仅接受 POST 并校验 CSRF（由核心 check() 统一处理）。
 * 插件页委托给 Plugin 类渲染。
 */
final class Admin
{
    public static function route(): void
    {
        need_admin();
        $tab = (string)($_GET['tab'] ?? 'dashboard');
        if (is_post_request()) self::handle_post($tab);
        $core_tabs = ['dashboard', 'posts', 'categories', 'tags', 'users', 'pages', 'settings', 'plugins'];
        // 非核心标签先尝试插件追加的后台标签（admin.tabs Hook），未命中回退仪表盘。
        $plugin_view = in_array($tab, $core_tabs, true) ? null : self::plugin_admin_tab_view($tab);
        $view = $plugin_view ?? match ($tab) {
            'posts' => self::posts_view(),
            'categories' => self::categories_view(),
            'tags' => self::tags_view(),
            'users' => self::users_view(),
            'pages' => self::pages_view(),
            'settings' => self::settings_view(),
            'plugins' => Plugin::admin_plugins_page_html(),
            default => self::dashboard_view(),
        };
        if ($plugin_view === null && !in_array($tab, $core_tabs, true)) $tab = 'dashboard';
        page('后台管理', '<div class="wrap single"><div class="main">' . self::tabs_html($tab) . $view . '</div></div>', [], true);
    }

    // 插件追加的后台标签内容：标签须同时声明在 admin.tabs Hook 与某启用插件的 admin_tabs 子页映射中（键一致）。
    // 未命中返回 null（调用方回退仪表盘）；内容自动包在卡片中，标题取 Hook 声明的标签名。
    private static function plugin_admin_tab_view(string $tab): ?string
    {
        $labels = (array)hook('admin.tabs', [], []);
        if (!array_key_exists($tab, $labels)) return null;
        foreach (plugins() as $plugin) {
            // 先取出再取键：?? 的告警抑制不穿透 (array) 强转，连写会对缺少该子页的插件产生 Undefined array key 告警。
            $admin_tabs = (array)($plugin['admin_tabs'] ?? []);
            $fn = $admin_tabs[$tab] ?? null;
            if (!is_string($fn) || $fn === '') continue;
            plugin_load($plugin);
            if (!plugin_callback_exists($fn)) continue;
            return '<div class="card"><div class="card-title">' . h((string)$labels[$tab]) . '</div>' . (string)call_user_func($fn, $plugin) . '</div>';
        }
        return null;
    }

    // 后台标签栏（插件可通过 admin.tabs Hook 追加）。
    private static function tabs_html(string $active): string
    {
        $tabs = [
            'dashboard' => '仪表盘',
            'posts' => '文章',
            'categories' => '分类',
            'tags' => '标签',
            'users' => '用户',
            'pages' => '页面',
            'settings' => '设置',
            'plugins' => '插件',
        ];
        foreach ((array)hook('admin.tabs', [], []) as $key => $label) {
            if (is_string($key) && is_string($label)) $tabs[$key] = $label;
        }
        $html = '<div class="admin-tabs" data-slot="admin.tabs">';
        foreach ($tabs as $key => $label) {
            $html .= '<a class="' . ($key === $active ? 'active' : '') . '" href="' . h(admin_url(['tab' => $key])) . '">' . h((string)$label) . '</a>';
        }
        return $html . '</div>';
    }

    // --- POST 处理 ---
    private static function handle_post(string $tab): void
    {
        $action = (string)($_POST['admin_action'] ?? '');
        if ($tab === 'settings' && $action === 'save_settings') {
            $values = [
                'site_name' => post('site_name', DB_STRING_MAX_LENGTH) ?: 'Mono',
                'site_description' => post('site_description', 255),
                'site_keywords' => post('site_keywords', 255),
                'posts_per_page' => (string)min(100, max(1, (int)($_POST['posts_per_page'] ?? 10))),
                'excerpt_length' => (string)min(500, max(20, (int)($_POST['excerpt_length'] ?? 160))),
                'pretty_url' => (string)((int)($_POST['pretty_url'] ?? 0) === 1 ? 1 : 0),
            ];
            // 「允许评论」仅评论插件启用时随表单提交；未启用时表单无此项，不写入以保留原值。
            if (isset(plugins()['comment'])) $values['allow_comment'] = (string)((int)($_POST['allow_comment'] ?? 0) === 1 ? 1 : 0);
            save_settings_values($values);
            set_flash('设置已保存');
            go(admin_url(['tab' => 'settings']));
        }
        if ($tab === 'categories') {
            if ($action === 'add_category') {
                $name = post('name', DB_STRING_MAX_LENGTH);
                if ($name === '') err('分类名不能为空');
                app_db_upsert('app_categories', ['name' => $name, 'slug' => slugify($name) ?: md5($name), 'sort' => (int)($_POST['sort'] ?? 0)], ['name']);
                set_flash('分类已添加');
                go(admin_url(['tab' => 'categories']));
            }
            if ($action === 'delete_category') {
                $id = (int)($_POST['id'] ?? 0);
                q('UPDATE app_posts SET category_id=0 WHERE category_id=?', [$id]);
                q('DELETE FROM app_categories WHERE id=?', [$id]);
                set_flash('分类已删除');
                go(admin_url(['tab' => 'categories']));
            }
        }
        if ($tab === 'tags' && $action === 'delete_tag') {
            $id = (int)($_POST['id'] ?? 0);
            q('DELETE FROM app_post_tags WHERE tag_id=?', [$id]);
            q('DELETE FROM app_tags WHERE id=?', [$id]);
            set_flash('标签已删除');
            go(admin_url(['tab' => 'tags']));
        }
        if ($tab === 'posts' && $action === 'toggle_status') {
            $id = (int)($_POST['id'] ?? 0);
            q('UPDATE app_posts SET status=1-status, updated_at=? WHERE id=?', [now(), $id]);
            set_flash('文章状态已更新');
            go(admin_url(['tab' => 'posts']));
        }
        // 个人资料（设置页=本人；用户编辑页=指定 user_id）。
        if ($action === 'save_profile') {
            $my_id = (int)me()['id'];
            $id = (int)($_POST['user_id'] ?? 0) ?: $my_id;
            row('app_users', 'id', $id) ?: err('用户不存在');
            $nickname = post('nickname', DB_STRING_MAX_LENGTH);
            $email = post('email', DB_STRING_MAX_LENGTH);
            if ($nickname === '' || $email === '') err('昵称与邮箱不能为空');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) err('邮箱格式不正确');
            if (one('SELECT id FROM app_users WHERE email=? AND id<>?', [$email, $id])) err('邮箱已被其他用户使用');
            q('UPDATE app_users SET nickname=?, email=? WHERE id=?', [$nickname, $email, $id]);
            if ((int)($_POST['remove_avatar'] ?? 0) === 1) self::delete_avatar($id); else self::save_avatar_upload($id);
            set_flash('资料已保存');
            go(isset($_POST['user_id']) ? admin_url(['tab' => 'users', 'edit' => $id]) : admin_url(['tab' => 'settings']));
        }
        // 修改/重置密码（本人需验旧密码；重置他人后其已登录会话自动失效）。
        if ($action === 'change_password') {
            $my_id = (int)me()['id'];
            $id = (int)($_POST['user_id'] ?? 0) ?: $my_id;
            $u = row('app_users', 'id', $id) ?: err('用户不存在');
            $new = (string)($_POST['new_password'] ?? '');
            if ($id === $my_id && !password_verify((string)($_POST['old_password'] ?? ''), (string)$u['password'])) err('当前密码不正确');
            if (mb_strlen($new) < 6) err('密码至少 6 位');
            if ($new !== (string)($_POST['confirm_password'] ?? '')) err('两次输入的密码不一致');
            q('UPDATE app_users SET password=? WHERE id=?', [password_hash($new, PASSWORD_DEFAULT), $id]);
            if ($id === $my_id) start_login($my_id); // 密码哈希是 Cookie 签名密钥，改密后必须重发 Cookie
            set_flash($id === $my_id ? '密码已修改' : '密码已重置');
            go(isset($_POST['user_id']) ? admin_url(['tab' => 'users', 'edit' => $id]) : admin_url(['tab' => 'settings']));
        }
        if ($tab === 'users' && $action === 'add_user') {
            $nickname = post('nickname', DB_STRING_MAX_LENGTH);
            $email = post('email', DB_STRING_MAX_LENGTH);
            $password = (string)($_POST['password'] ?? '');
            if ($nickname === '' || $email === '') err('昵称与邮箱不能为空');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) err('邮箱格式不正确');
            if (mb_strlen($password) < 6) err('密码至少 6 位');
            if (one('SELECT id FROM app_users WHERE email=?', [$email])) err('邮箱已被使用');
            q('INSERT INTO app_users(nickname,email,password,is_admin,avatar,created_at) VALUES(?,?,?,?,?,?)',
                [$nickname, $email, password_hash($password, PASSWORD_DEFAULT), (int)($_POST['is_admin'] ?? 0) === 1 ? 1 : 0, '', now()]);
            set_flash('用户已添加');
            go(admin_url(['tab' => 'users']));
        }
        if ($tab === 'users' && $action === 'toggle_admin') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id === (int)me()['id']) err('不能修改自己的角色');
            $u = row('app_users', 'id', $id) ?: err('用户不存在');
            if ((int)$u['is_admin'] === 1 && (int)val('SELECT COUNT(*) FROM app_users WHERE is_admin=1') <= 1) err('至少保留一名管理员');
            q('UPDATE app_users SET is_admin=1-is_admin WHERE id=?', [$id]);
            set_flash('角色已更新');
            go(admin_url(['tab' => 'users', 'edit' => $id]));
        }
        if ($tab === 'users' && $action === 'delete_user') {
            $id = (int)($_POST['id'] ?? 0);
            $my_id = (int)me()['id'];
            if ($id === $my_id) err('不能删除自己的账号');
            $u = row('app_users', 'id', $id) ?: err('用户不存在');
            if ((int)$u['is_admin'] === 1 && (int)val('SELECT COUNT(*) FROM app_users WHERE is_admin=1') <= 1) err('至少保留一名管理员');
            q('UPDATE app_posts SET user_id=? WHERE user_id=?', [$my_id, $id]);
            self::delete_avatar($id);
            q('DELETE FROM app_users WHERE id=?', [$id]);
            set_flash('用户已删除，其文章已归入你的名下');
            go(admin_url(['tab' => 'users']));
        }
        if ($tab === 'pages' && $action === 'save_page') {
            $id = (int)($_POST['id'] ?? 0);
            $title = post('title', DB_STRING_MAX_LENGTH);
            if ($title === '') err('标题不能为空');
            $slug = slugify(post('slug', DB_STRING_MAX_LENGTH)) ?: slugify($title);
            if ($slug === '') $slug = 'page-' . $id;
            if (one('SELECT id FROM app_pages WHERE slug=? AND id<>?', [$slug, $id])) err('别名已被占用');
            $content = (string)($_POST['content'] ?? '');
            $status = (int)($_POST['status'] ?? 0) === 1 ? 1 : 0;
            $show_title = (int)($_POST['show_title'] ?? 0) === 1 ? 1 : 0;
            $sort = (int)($_POST['sort'] ?? 0);
            if ($id > 0) {
                row('app_pages', 'id', $id) ?: err('页面不存在');
                q('UPDATE app_pages SET slug=?,title=?,content=?,status=?,show_title=?,sort=?,updated_at=? WHERE id=?', [$slug, $title, $content, $status, $show_title, $sort, now(), $id]);
            } else {
                q('INSERT INTO app_pages(slug,title,content,status,show_title,sort,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)', [$slug, $title, $content, $status, $show_title, $sort, now(), now()]);
            }
            set_flash('页面已保存');
            go(admin_url(['tab' => 'pages']));
        }
        if ($tab === 'pages' && $action === 'delete_page') {
            q('DELETE FROM app_pages WHERE id=?', [(int)($_POST['id'] ?? 0)]);
            set_flash('页面已删除');
            go(admin_url(['tab' => 'pages']));
        }
        if ($tab === 'plugins') { Plugin::admin_plugins_handle_post(); }
    }

    // --- 仪表盘 ---
    private static function dashboard_view(): string
    {
        $posts = (int)val('SELECT COUNT(*) FROM app_posts');
        $published = (int)val('SELECT COUNT(*) FROM app_posts WHERE status=1');
        $cats = (int)val('SELECT COUNT(*) FROM app_categories');
        $tags = (int)val('SELECT COUNT(*) FROM app_tags');
        $plugins = (int)val('SELECT COUNT(*) FROM app_plugins WHERE enabled=1');
        $stat = fn(string $label, int $n): string => '<div class="card" style="text-align:center"><div style="font-size:28px;font-weight:700;color:var(--brand)">' . $n . '</div><div style="color:var(--text-muted);font-size:var(--font-size-sm)">' . h($label) . '</div></div>';
        $html = '<div class="stat-grid" data-slot="admin.dashboard.stats">'
            . $stat('文章', $posts) . $stat('已发布', $published) . $stat('分类', $cats) . $stat('标签', $tags) . $stat('启用插件', $plugins)
            . '</div>';
        $html .= '<div class="card"><div class="card-title">快捷操作</div><div class="quick-actions">'
            . '<a class="btn" href="' . h(route_url('write')) . '">写文章</a>'
            . '<a class="btn ghost" href="' . h(admin_url(['tab' => 'settings'])) . '">站点设置</a>'
            . '<a class="btn ghost" href="' . h(admin_url(['tab' => 'plugins'])) . '">管理插件</a>'
            . '<a class="btn ghost" href="' . h(route_url('home')) . '">查看站点</a>'
            . '</div></div>';
        // 最近文章
        $recent = all('SELECT id,title,status,created_at FROM app_posts ORDER BY created_at DESC, id DESC LIMIT 8');
        if ($recent) {
            $html .= '<div class="card"><div class="card-title">最近文章</div><table class="list"><tbody>';
            foreach ($recent as $p) {
                $html .= '<tr><td><a href="' . h(route_url('post', ['id' => (int)$p['id']])) . '">' . h($p['title']) . '</a></td>'
                    . '<td>' . ((int)$p['status'] === 1 ? '<span class="badge">已发布</span>' : '<span class="badge draft">草稿</span>') . '</td>'
                    . '<td style="text-align:right;color:var(--text-subtle)">' . h(date('Y-m-d', (int)$p['created_at'])) . '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        return $html;
    }

    // --- 文章管理 ---
    private static function posts_view(): string
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $size = 20; $offset = ($page - 1) * $size;
        $total = (int)val('SELECT COUNT(*) FROM app_posts');
        $posts = all("SELECT * FROM app_posts ORDER BY created_at DESC, id DESC LIMIT $size OFFSET $offset");
        $html = '<div class="card"><div class="card-title">文章管理（' . $total . '）</div>';
        if (!$posts) return $html . '<p style="color:var(--text-muted)">还没有文章。</p></div>';
        $html .= '<table class="list"><thead><tr><th>标题</th><th>分类</th><th>状态</th><th>阅读</th><th>时间</th><th class="actions">操作</th></tr></thead><tbody>';
        foreach ($posts as $p) {
            $cat = (int)$p['category_id'] > 0 ? category_by_id((int)$p['category_id']) : null;
            $html .= '<tr><td><a href="' . h(route_url('post', ['id' => (int)$p['id']])) . '">' . h(cut((string)$p['title'], 30)) . '</a></td>'
                . '<td>' . h($cat['name'] ?? '—') . '</td>'
                . '<td>' . ((int)$p['status'] === 1 ? '<span class="badge">已发布</span>' : '<span class="badge draft">草稿</span>') . '</td>'
                . '<td>' . (int)$p['view_count'] . '</td>'
                . '<td style="color:var(--text-subtle)">' . h(date('Y-m-d', (int)$p['created_at'])) . '</td>'
                . '<td class="actions"><div class="btn-row" style="justify-content:flex-end">'
                . '<a class="btn sm ghost" href="' . h(route_url('edit', ['id' => (int)$p['id']])) . '">编辑</a>'
                . self::action_form('toggle_status', (int)$p['status'] === 1 ? '转草稿' : '发布', ['id' => (int)$p['id']])
                . post_action_form(route_url('delete', ['id' => (int)$p['id']]), '删除', [], 'btn sm danger', '确定删除这篇文章吗？')
                . '</div></td></tr>';
        }
        $html .= '</tbody></table></div>';
        return $html . paginate($total, $page, $size, fn(int $pg): string => admin_url(['tab' => 'posts', 'page' => $pg]));
    }

    // --- 分类管理 ---
    private static function categories_view(): string
    {
        $cats = all('SELECT c.*, (SELECT COUNT(*) FROM app_posts p WHERE p.category_id=c.id) AS cnt FROM app_categories c ORDER BY c.sort ASC, c.id ASC');
        $html = '<div class="card"><div class="card-title">添加分类</div><form method="post">' . form_token()
            . '<input type="hidden" name="admin_action" value="add_category">'
            . '<div class="form-grid-2">' . input('分类名称', 'name', '', 'text', true) . input('排序', 'sort', '0', 'number') . '</div>'
            . '<button class="btn" type="submit">添加</button></form></div>';
        $html .= '<div class="card"><div class="card-title">分类列表</div>';
        if (!$cats) return $html . '<p style="color:var(--text-muted)">还没有分类。</p></div>';
        $html .= '<table class="list"><thead><tr><th>名称</th><th>别名</th><th>文章数</th><th>排序</th><th class="actions">操作</th></tr></thead><tbody>';
        foreach ($cats as $c) {
            $html .= '<tr><td>' . h($c['name']) . '</td><td style="color:var(--text-subtle)">' . h($c['slug']) . '</td><td>' . (int)$c['cnt'] . '</td><td>' . (int)$c['sort'] . '</td>'
                . '<td class="actions">' . self::action_form('delete_category', '删除', ['id' => (int)$c['id']], 'btn sm danger', '确定删除该分类吗？分类下文章将变为未分类。') . '</td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    // --- 标签管理 ---
    private static function tags_view(): string
    {
        $tags = all('SELECT t.*, (SELECT COUNT(*) FROM app_post_tags pt WHERE pt.tag_id=t.id) AS cnt FROM app_tags t ORDER BY cnt DESC, t.id DESC LIMIT 200');
        $html = '<div class="card"><div class="card-title">标签列表</div>';
        if (!$tags) return $html . '<p style="color:var(--text-muted)">还没有标签。写文章时填写标签会自动创建。</p></div>';
        $html .= '<table class="list"><thead><tr><th>名称</th><th>文章数</th><th class="actions">操作</th></tr></thead><tbody>';
        foreach ($tags as $t) {
            $html .= '<tr><td><a class="tag" href="' . h(route_url('tag', ['id' => (int)$t['id']])) . '">' . h($t['name']) . '</a></td><td>' . (int)$t['cnt'] . '</td>'
                . '<td class="actions">' . self::action_form('delete_tag', '删除', ['id' => (int)$t['id']], 'btn sm danger', '确定删除该标签吗？') . '</td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    // --- 用户管理 ---
    private static function users_view(): string
    {
        $edit_id = (int)($_GET['edit'] ?? 0);
        if ($edit_id > 0) return self::user_edit_view($edit_id);
        $users = all('SELECT u.*, (SELECT COUNT(*) FROM app_posts p WHERE p.user_id=u.id) AS cnt FROM app_users u ORDER BY u.id ASC');
        $html = '<div class="card"><div class="card-title">添加用户</div><form method="post">' . form_token()
            . '<input type="hidden" name="admin_action" value="add_user">'
            . '<div class="form-grid-2">' . input('昵称', 'nickname', '', 'text', true) . input('邮箱', 'email', '', 'email', true) . '</div>'
            . input('初始密码', 'password', '', 'password', true, '至少 6 位')
            . checkbox('设为管理员', 'is_admin', false)
            . '<button class="btn" type="submit">添加</button></form></div>';
        $html .= '<div class="card"><div class="card-title">用户列表（' . count($users) . '）</div>';
        if (!$users) return $html . '<p style="color:var(--text-muted)">还没有用户。</p></div>';
        $my_id = (int)me()['id'];
        $html .= '<table class="list"><thead><tr><th>用户</th><th>邮箱</th><th>角色</th><th>文章</th><th>注册时间</th><th class="actions">操作</th></tr></thead><tbody>';
        foreach ($users as $u) {
            $uid = (int)$u['id'];
            $html .= '<tr><td><div style="display:flex;align-items:center;gap:10px"><img class="avatar" src="' . h(user_avatar_url($u, 80)) . '" alt="">' . h((string)$u['nickname']) . ($uid === $my_id ? ' <span class="badge">本人</span>' : '') . '</div></td>'
                . '<td style="color:var(--text-subtle)">' . h((string)$u['email']) . '</td>'
                . '<td>' . ((int)$u['is_admin'] === 1 ? '<span class="badge" style="background:var(--brand-soft);color:var(--brand);border-color:transparent">管理员</span>' : '<span class="badge">成员</span>') . '</td>'
                . '<td>' . (int)$u['cnt'] . '</td>'
                . '<td style="color:var(--text-subtle)">' . h(date('Y-m-d', (int)$u['created_at'])) . '</td>'
                . '<td class="actions"><div class="btn-row" style="justify-content:flex-end">'
                . '<a class="btn sm ghost" href="' . h(admin_url(['tab' => 'users', 'edit' => $uid])) . '">编辑</a>'
                . ($uid === $my_id ? '' : self::action_form('toggle_admin', (int)$u['is_admin'] === 1 ? '降为成员' : '设为管理', ['id' => $uid], 'btn sm ghost', '确定调整该用户的角色吗？'))
                . ($uid === $my_id ? '' : self::action_form('delete_user', '删除', ['id' => $uid], 'btn sm danger', '确定删除该用户吗？其文章将归入你的名下。'))
                . '</div></td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    // 用户编辑子视图：资料/头像 + 重置密码 + 危险操作。
    private static function user_edit_view(int $id): string
    {
        $u = row('app_users', 'id', $id) ?: err('用户不存在');
        $my_id = (int)me()['id'];
        $html = '<div class="btn-row" style="margin-bottom:16px"><a class="btn sm ghost" href="' . h(admin_url(['tab' => 'users'])) . '">← 返回用户列表</a></div>';
        $html .= '<div class="card"><div class="card-title">编辑用户：' . h((string)$u['nickname']) . '</div>'
            . '<form method="post" enctype="multipart/form-data">' . form_token()
            . '<input type="hidden" name="admin_action" value="save_profile">'
            . '<input type="hidden" name="user_id" value="' . $id . '">'
            . '<div style="display:flex;align-items:center;gap:14px;margin-bottom:14px"><img class="avatar" style="width:64px;height:64px" src="' . h(user_avatar_url($u, 128)) . '" alt="">'
            . '<span style="color:var(--text-muted);font-size:var(--font-size-sm)">' . ((int)$u['is_admin'] === 1 ? '管理员' : '成员') . ' · 注册于 ' . h(date('Y-m-d', (int)$u['created_at'])) . '</span></div>'
            . '<div class="form-grid-2">' . input('昵称', 'nickname', (string)$u['nickname'], 'text', true) . input('邮箱', 'email', (string)$u['email'], 'email', true) . '</div>'
            . self::avatar_field_html()
            . checkbox('恢复默认头像', 'remove_avatar', false, '勾选后移除自定义头像')
            . '<button class="btn" type="submit">保存</button></form></div>';
        $html .= '<div class="card"><div class="card-title">' . ($id === $my_id ? '修改密码' : '重置密码') . '</div><form method="post">' . form_token()
            . '<input type="hidden" name="admin_action" value="change_password">'
            . '<input type="hidden" name="user_id" value="' . $id . '">'
            . ($id === $my_id ? input('当前密码', 'old_password', '', 'password', true) : '')
            . '<div class="form-grid-2">' . input('新密码', 'new_password', '', 'password', true, '至少 6 位') . input('确认新密码', 'confirm_password', '', 'password', true) . '</div>'
            . '<button class="btn" type="submit">' . ($id === $my_id ? '修改密码' : '重置密码') . '</button></form>'
            . '<p style="color:var(--text-muted);font-size:var(--font-size-sm);margin:10px 0 0">重置后该用户已登录的会话将失效。</p></div>';
        if ($id !== $my_id) {
            $html .= '<div class="card"><div class="card-title">危险操作</div><div class="btn-row">'
                . self::action_form('toggle_admin', (int)$u['is_admin'] === 1 ? '降为成员' : '设为管理员', ['id' => $id], 'btn ghost', '确定调整该用户的角色吗？')
                . self::action_form('delete_user', '删除用户', ['id' => $id], 'btn danger', '确定删除该用户吗？其文章将归入你的名下。')
                . '</div></div>';
        }
        return $html;
    }

    // --- 页面管理（关于/友链等独立页面）---
    private static function pages_view(): string
    {
        if (array_key_exists('edit', $_GET)) return self::page_edit_view((int)($_GET['edit'] ?? 0));
        $pages = all('SELECT * FROM app_pages ORDER BY sort ASC, id ASC');
        $html = '<div class="card"><div class="card-title">页面列表（' . count($pages) . '）</div>';
        if (!$pages) {
            $html .= '<p style="color:var(--text-muted)">还没有独立页面。点下方「新建页面」创建关于页、友链页等，发布后自动进入导航。</p>';
        } else {
            $html .= '<table class="list"><thead><tr><th>标题</th><th>别名</th><th>状态</th><th>排序</th><th>更新</th><th class="actions">操作</th></tr></thead><tbody>';
            foreach ($pages as $p) {
                $html .= '<tr><td><a href="' . h(route_url('page', ['slug' => (string)$p['slug']])) . '">' . h((string)$p['title']) . '</a></td>'
                    . '<td style="color:var(--text-subtle)">' . h((string)$p['slug']) . '</td>'
                    . '<td>' . ((int)$p['status'] === 1 ? '<span class="badge">已发布</span>' : '<span class="badge draft">草稿</span>') . '</td>'
                    . '<td>' . (int)$p['sort'] . '</td>'
                    . '<td style="color:var(--text-subtle)">' . h(date('Y-m-d', (int)$p['updated_at'])) . '</td>'
                    . '<td class="actions"><div class="btn-row" style="justify-content:flex-end">'
                    . '<a class="btn sm ghost" href="' . h(admin_url(['tab' => 'pages', 'edit' => (int)$p['id']])) . '">编辑</a>'
                    . self::action_form('delete_page', '删除', ['id' => (int)$p['id']], 'btn sm danger', '确定删除该页面吗？')
                    . '</div></td></tr>';
            }
            $html .= '</tbody></table>';
        }
        return $html . '</div><div class="btn-row"><a class="btn" href="' . h(admin_url(['tab' => 'pages', 'edit' => 0])) . '">新建页面</a></div>';
    }

    // 页面编辑子视图（id=0 为新建）。
    private static function page_edit_view(int $id): string
    {
        $p = $id > 0 ? (row('app_pages', 'id', $id) ?: err('页面不存在')) : null;
        $html = '<div class="btn-row" style="margin-bottom:16px"><a class="btn sm ghost" href="' . h(admin_url(['tab' => 'pages'])) . '">← 返回页面列表</a></div>';
        $html .= '<div class="card"><div class="card-title">' . ($p ? '编辑页面：' . h((string)$p['title']) : '新建页面') . '</div><form method="post">' . form_token()
            . '<input type="hidden" name="admin_action" value="save_page">'
            . ($p ? '<input type="hidden" name="id" value="' . $id . '">' : '')
            . '<div class="form-grid-2">' . input('标题', 'title', (string)($p['title'] ?? ''), 'text', true) . input('别名', 'slug', (string)($p['slug'] ?? ''), 'text', false, 'URL 标识，如 about；留空按标题生成') . '</div>'
            . textarea('内容（Markdown）', 'content', (string)($p['content'] ?? ''), false, '', 'rows="16"')
            . '<div class="form-grid-2">' . input('排序', 'sort', (string)(int)($p['sort'] ?? 0), 'number', false, '数字越小越靠前') . checkbox('发布', 'status', (int)($p['status'] ?? 1) === 1, '未发布仅管理员可见') . '</div>'
            . checkbox('显示标题', 'show_title', (int)($p['show_title'] ?? 1) === 1, '取消后页面不渲染顶部大标题，适合 Markdown 正文自带标题')
            . '<button class="btn" type="submit">保存页面</button></form></div>';
        return $html;
    }

    // 头像上传表单项（PNG/JPG/GIF/WebP，≤2MB）。
    private static function avatar_field_html(): string
    {
        return '<label class="grid">' . form_field_caption('头像', '支持 PNG/JPG/GIF/WebP，不超过 2MB；留空保持当前头像') . '<input type="file" name="avatar" accept="image/png,image/jpeg,image/gif,image/webp"></label>';
    }

    // 校验并保存上传头像：错误码 → 大小 → is_uploaded_file → getimagesize 真实 MIME 白名单 → 固定命名落盘。
    private static function save_avatar_upload(int $uid): void
    {
        $f = $_FILES['avatar'] ?? null;
        if (!$f || (int)$f['error'] === UPLOAD_ERR_NO_FILE) return;
        if ((int)$f['error'] !== UPLOAD_ERR_OK) err('头像上传失败');
        if ((int)$f['size'] > 2 * 1024 * 1024) err('头像不能超过 2MB');
        if (!is_uploaded_file((string)$f['tmp_name'])) err('非法的上传文件');
        $info = @getimagesize((string)$f['tmp_name']);
        $ext = $info === false ? false : array_search((string)$info['mime'], ['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'], true);
        if ($ext === false) err('头像仅支持 PNG/JPG/GIF/WebP 格式');
        if (!is_dir(avatar_dir())) mkdir(avatar_dir(), 0755, true);
        self::delete_avatar($uid);
        if (!move_uploaded_file((string)$f['tmp_name'], avatar_file($uid, (string)$ext))) err('头像保存失败，请检查目录写权限');
        q('UPDATE app_users SET avatar=? WHERE id=?', [(string)$ext, $uid]);
    }

    // 移除自定义头像文件并清空列。
    private static function delete_avatar(int $uid): void
    {
        $ext = (string)(row('app_users', 'id', $uid)['avatar'] ?? '');
        if ($ext === '') return;
        $file = avatar_file($uid, $ext);
        if (is_file($file)) unlink($file);
        q("UPDATE app_users SET avatar='' WHERE id=?", [$uid]);
    }

    // --- 站点设置 ---
    private static function settings_view(): string
    {
        // 「允许评论」是评论插件的配套项：插件未启用时该项无意义，不渲染。
        $comment_on = isset(plugins()['comment']);
        $html = '<div class="card"><div class="card-title">站点设置</div><form method="post">' . form_token()
            . '<input type="hidden" name="admin_action" value="save_settings">'
            . '<div class="form-grid-2">'
            . input('站点名称', 'site_name', setting('site_name', 'Mono'), 'text', true)
            . input('每页文章数', 'posts_per_page', setting('posts_per_page', '10'), 'number')
            . '</div>'
            . input('站点描述', 'site_description', setting('site_description'))
            . input('关键词', 'site_keywords', setting('site_keywords'), 'text', false, '用于 SEO，逗号分隔')
            . input('摘要长度', 'excerpt_length', setting('excerpt_length', '160'), 'number')
            . ($comment_on ? checkbox('允许评论', 'allow_comment', setting('allow_comment', '1') === '1', '全站总开关；单篇文章可在写文章页单独关闭') : '')
            . checkbox('启用伪静态', 'pretty_url', setting('pretty_url', '0') === '1', '需服务器配置 URL 重写')
            . '<button class="btn" type="submit">保存设置</button></form></div>';
        // 个人资料（含头像上传）
        $me = me();
        $html .= '<div class="card"><div class="card-title">个人资料</div><form method="post" enctype="multipart/form-data">' . form_token()
            . '<input type="hidden" name="admin_action" value="save_profile">'
            . '<div style="display:flex;align-items:center;gap:14px;margin-bottom:14px"><img class="avatar" style="width:64px;height:64px" src="' . h(user_avatar_url($me, 128)) . '" alt="">'
            . '<div style="color:var(--text-muted);font-size:var(--font-size-sm)">当前登录：' . h((string)($me['nickname'] ?? '')) . '（' . h((string)($me['email'] ?? '')) . '）</div></div>'
            . '<div class="form-grid-2">' . input('昵称', 'nickname', (string)$me['nickname'], 'text', true) . input('邮箱', 'email', (string)$me['email'], 'email', true) . '</div>'
            . self::avatar_field_html()
            . checkbox('恢复默认头像', 'remove_avatar', false, '勾选后移除自定义头像')
            . '<button class="btn" type="submit">保存资料</button></form></div>';
        // 修改密码
        $html .= '<div class="card"><div class="card-title">修改密码</div><form method="post">' . form_token()
            . '<input type="hidden" name="admin_action" value="change_password">'
            . input('当前密码', 'old_password', '', 'password', true)
            . '<div class="form-grid-2">' . input('新密码', 'new_password', '', 'password', true, '至少 6 位') . input('确认新密码', 'confirm_password', '', 'password', true) . '</div>'
            . '<button class="btn" type="submit">修改密码</button></form></div>';
        return $html;
    }

    // 后台内联 POST 操作表单助手。
    private static function action_form(string $action, string $label, array $fields = [], string $class = 'btn sm ghost', string $confirm = ''): string
    {
        $hidden = form_token() . '<input type="hidden" name="admin_action" value="' . h($action) . '">';
        foreach ($fields as $k => $v) $hidden .= '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">';
        return '<form method="post" style="display:inline"' . ($confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '') . '>' . $hidden . '<button type="submit" class="' . h($class) . '">' . h($label) . '</button></form>';
    }

    // 计划任务入口（供外部 cron 调用）。CLI 方式本地执行、天然受信；
    // HTTP 方式必须携带 ?token=（cron_secret_url() 自动生成并展示在插件配置页），防止被任意触发。
    public static function cron_route(): void
    {
        if (PHP_SAPI !== 'cli') {
            $expected = (string)setting('cron_token', '');
            $given = (string)($_GET['token'] ?? '');
            if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
                http_response_code(403);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'forbidden: invalid or missing cron token';
                exit;
            }
        }
        header('Content-Type: text/plain; charset=utf-8');
        $ran = Plugin::cron_run_due_tasks();
        echo 'ok, ran ' . (int)$ran . ' task(s) at ' . date('c');
        exit;
    }
}
