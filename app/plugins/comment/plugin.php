<?php
if (!defined('APP_ROOT')) exit;

/**
 * 评论插件（comment）。
 *
 * 「万物皆插件」：核心不含评论逻辑，评论能力完全由本插件通过 Hook 与路由提供。
 * - hook post.content_after：在文章正文后渲染评论列表与发表表单（详情页单次触发；平铺 1 次查询、楼中楼 2 次，无 N+1）。
 * - 楼中楼模式（可选，nested）：回复以 parent_id 挂到顶层评论下两层展示；旧库在渲染 / 提交 / 后台入口自动补列。
 * - route comment_submit：POST 提交评论（require_post + CSRF，支持游客昵称/邮箱）。
 * - 游客身份记忆：昵称 / 邮箱存入 localStorage（mono_guest_author / mono_guest_email，与动态评论共用），有记忆即预填并将字段折叠为一行摘要。
 * - 待审自见：先审后显模式下，提交者本机通过签名 cookie 记住自己提交的评论，待审期间可见（标「审核中」，仅本人）；通过 / 拒绝后自动移出并清理记录。
 * - route comment_delete：管理员删除评论。
 * - admin_tabs comment：插件配置子页只放设置；后台「评论」标签（admin.tabs）为分页审核列表，二者共用同一回调。
 * 数据表 plugin_comment_comments 使用插件前缀，卸载时按 keep_data 决定是否删除。
 */

// 归一化配置（旧配置缺字段不报错）。
function comment_config(): array
{
    $raw = plugin_config('comment', []);
    return [
        'require_login' => (int)($raw['require_login'] ?? 0) === 1,
        'moderate' => (int)($raw['moderate'] ?? 0) === 1,   // 先审后显
        'nested' => (int)($raw['nested'] ?? 0) === 1,       // 楼中楼（回复嵌套显示）
        'per_page' => min(100, max(5, (int)($raw['per_page'] ?? 50))),
    ];
}

function comment_install(array $plugin): void
{
    $t = app_db_types();
    app_db_create_table('plugin_comment_comments', "id {$t['id']},post_id {$t['uint']} NOT NULL,parent_id {$t['uint']} NOT NULL DEFAULT 0,author {$t['string']} NOT NULL,email {$t['string']},content {$t['text']} NOT NULL,status {$t['uint']} NOT NULL DEFAULT 1,created_at {$t['uint']} NOT NULL");
    app_db_create_index('idx_comment_post', 'plugin_comment_comments (post_id,status,created_at)');
    app_db_create_index('idx_comment_parent', 'plugin_comment_comments (parent_id,status)');
}

// 表结构懒升级（幂等）：1.6.0 起新增楼中楼的 parent_id 列。
// 插件文件升级不会自动重跑 install，故在渲染 / 提交 / 后台入口调用一次补齐；setting 标记后后续请求零成本跳过。
function comment_upgrade_schema(): void
{
    if (setting('plugin_comment_schema', '1') === '2') return;
    $t = app_db_types();
    app_db_ensure_columns('plugin_comment_comments', ['parent_id' => $t['uint'] . ' NOT NULL DEFAULT 0']);
    app_db_create_index('idx_comment_parent', 'plugin_comment_comments (parent_id,status)');
    save_settings_values(['plugin_comment_schema' => '2']);
}

function comment_uninstall(array $plugin, bool $keep_data = true): void
{
    if (!$keep_data) {
        app_db_drop_index('idx_comment_post', 'plugin_comment_comments');
        app_db_drop_table('plugin_comment_comments');
    }
}

function comment_css(): string
{
    return <<<'CSS'
.comment-section{margin-top:16px}
.comment-section .card-title{font-size:var(--font-size-lg)}
.comment-item{display:flex;gap:12px;padding:14px 0;border-bottom:1px solid var(--line-soft)}
.comment-item:last-child{border-bottom:0}
.comment-item img{width:40px;height:40px;border-radius:50%;flex-shrink:0}
.comment-body{min-width:0;flex:1}
.comment-head{display:flex;align-items:center;gap:8px;font-size:var(--font-size-sm);margin-bottom:4px}
.comment-head .name{font-weight:600;color:var(--text)}
.comment-head .time{color:var(--text-subtle);font-size:var(--font-size-xs)}
.comment-reply-btn{border:0;background:transparent;padding:0 4px;font-size:var(--font-size-xs);color:var(--text-subtle);cursor:pointer;border-radius:4px}
.comment-reply-btn:hover{color:var(--primary);background:var(--accent)}
.comment-text{font-size:var(--font-size-md);color:var(--text);white-space:pre-wrap;word-break:break-word}
.comment-text code{background:var(--muted);border:1px solid var(--border);border-radius:4px;padding:1px 5px;font-size:.9em;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
.comment-text a{color:var(--primary);text-decoration:underline;text-underline-offset:2px;word-break:break-all}
.comment-mention{color:var(--primary);font-weight:600}
.comment-empty{color:var(--text-muted);font-size:var(--font-size-sm);padding:8px 0}
/* 评论轻量编辑器：工具栏（表情 + 格式）+ 无边框输入区，聚焦整框高亮 */
/* 注意：外层不能加 overflow:hidden，否则会裁切掉绝对定位的表情面板 */
.comment-editor-box{border:1px solid var(--input);border-radius:calc(var(--radius) - 4px);background:var(--background);transition:border-color .15s,box-shadow .15s}
.comment-editor-box:focus-within{border-color:var(--ring);box-shadow:0 0 0 3px color-mix(in oklab,var(--ring) 28%,transparent)}
.comment-toolbar{display:flex;align-items:center;flex-wrap:wrap;gap:3px;padding:5px 6px;background:var(--muted);border-bottom:1px solid var(--border);position:relative;border-radius:calc(var(--radius) - 5px) calc(var(--radius) - 5px) 0 0}
.comment-tbtn{display:inline-flex;align-items:center;justify-content:center;border:0;background:transparent;border-radius:5px;width:28px;height:26px;padding:0;color:var(--muted-foreground);cursor:pointer;line-height:1}
.comment-tbtn:hover{background:var(--accent);color:var(--accent-foreground)}
.comment-tbtn svg{display:block;width:15px;height:15px}
.comment-tbtn-sep{width:1px;height:16px;background:var(--border);margin:0 4px;flex:0 0 auto}
/* 表情面板：限高 + 内部滚动，窄屏不溢出；不依赖编辑器高度就能看全 */
.comment-emoji-panel{display:none;position:absolute;top:calc(100% + 6px);left:6px;z-index:60;width:min(264px,calc(100vw - 40px));max-height:min(48vh,224px);overflow:auto;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;padding:6px;grid-template-columns:repeat(8,1fr);gap:2px;background:var(--card);border:1px solid var(--border);border-radius:calc(var(--radius) - 4px);box-shadow:var(--shadow-md)}
.comment-emoji-panel.open{display:grid}
.comment-emoji-item{border:0;background:transparent;font-size:18px;line-height:1;padding:5px 0;border-radius:5px;cursor:pointer}
.comment-emoji-item:hover{background:var(--accent)}
.comment-editor-box textarea{display:block;width:100%;min-height:90px;padding:10px 12px;border:0;border-radius:0 0 calc(var(--radius) - 5px) calc(var(--radius) - 5px);background:transparent;resize:vertical}
.comment-editor-box textarea:focus{outline:none;border:0;box-shadow:none}
.comment-pending{background:color-mix(in oklab,var(--warning) 12%,transparent);border:1px solid color-mix(in oklab,var(--warning) 32%,transparent);color:var(--warning);padding:8px 12px;border-radius:6px;font-size:var(--font-size-sm);margin-bottom:12px}
/* 发表按钮与输入区之间留出间距，不再贴紧 */
.comment-form>.btn{margin-top:14px}
/* 楼中楼（可选模式）：线程容器 + 缩进的子回复列表；顶层楼层可锚点直达 */
.comment-thread{border-bottom:1px solid var(--line-soft);padding:14px 0}
.comment-thread>.comment-item{padding:0;border-bottom:0}
.comment-thread>.comment-item[id]{scroll-margin-top:84px}
.comment-children{margin:12px 0 2px 52px;padding-left:14px;border-left:2px solid var(--line-soft)}
.comment-children .comment-item{padding:8px 0;border-bottom:0}
.comment-children .comment-item+.comment-item{border-top:1px dashed var(--border)}
.comment-children .comment-item img{width:30px;height:30px}
/* 「正在回复 @xxx」提示条（楼中楼模式，可取消） */
.comment-reply-hint{display:flex;align-items:center;gap:6px;margin:0 0 8px;font-size:var(--font-size-xs);color:var(--muted-foreground)}
.comment-reply-hint b{color:var(--primary);font-weight:600}
.comment-reply-cancel{border:0;background:transparent;padding:0 2px;font-size:var(--font-size-xs);color:var(--text-subtle);cursor:pointer;border-radius:4px}
.comment-reply-cancel:hover{color:var(--primary);background:var(--accent)}
/* 游客昵称 / 邮箱记忆（localStorage，与动态评论共用）：填过一次即自动预填，有记忆时表单折叠为一行摘要（可展开修改） */
.comment-guest.collapsed .form-grid-2{display:none}
.comment-guest-remember{display:none;align-items:center;gap:2px;font-size:var(--font-size-xs);color:var(--text-subtle);margin-bottom:16px}
.comment-guest.collapsed .comment-guest-remember{display:flex}
.comment-guest-remember b{color:var(--primary);font-weight:600}
.comment-guest-edit{border:0;background:transparent;padding:0 2px;font-size:var(--font-size-xs);color:var(--text-subtle);cursor:pointer;border-radius:4px}
.comment-guest-edit:hover{color:var(--primary);background:var(--accent)}
/* 我的待审评论（先审后显时仅提交者本机可见；通过 / 拒绝后自动移出，记录同步清理） */
.comment-mine{margin-top:14px;padding-top:12px;border-top:1px dashed var(--border)}
.comment-mine-note{margin-bottom:2px;font-size:var(--font-size-xs);color:var(--text-subtle)}
.comment-mine .comment-item{opacity:.75}
.comment-mine-tag{display:inline-block;margin-left:6px;padding:0 6px;border-radius:4px;font-size:var(--font-size-xs);line-height:18px;color:var(--warning);background:color-mix(in oklab,var(--warning) 12%,transparent);border:1px solid color-mix(in oklab,var(--warning) 32%,transparent)}
CSS;
}

// 工具栏图标（Lucide 风格，与核心顶栏/编辑器图标同一规格：24 视窗 + stroke-width 2 + currentColor）。
function comment_icon(string $name): string
{
    $paths = [
        'smile' => '<circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/>',
        'bold' => '<path d="M6 4h8a4 4 0 0 1 4 4 4 4 0 0 1-4 4H6z"/><path d="M6 12h9a4 4 0 0 1 4 4 4 4 0 0 1-4 4H6z"/>',
        'italic' => '<line x1="19" y1="4" x2="10" y2="4"/><line x1="14" y1="20" x2="5" y2="20"/><line x1="15" y1="4" x2="9" y2="20"/>',
        'code' => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($paths[$name] ?? '') . '</svg>';
}

// 工具栏按钮（图标 + title/aria-label，不占文字宽度）。
function comment_tbtn(string $icon, string $label, string $attr = ''): string
{
    return '<button type="button" class="comment-tbtn" title="' . h($label) . '" aria-label="' . h($label) . '" ' . $attr . '>'
        . comment_icon($icon) . '</button>';
}

// --- 我的待审评论（本机可见）---
// 先审后显模式下，提交者通过签名 cookie 记住自己提交的评论 id（HMAC 以本机 CSRF 令牌为密钥，
// 防伪造他人 id）；渲染时仅展示仍待审（status=0）的条目——通过（1）/ 拒绝（-1）后自动移出并清理记录。
function comment_mine_token(int $id): string
{
    return substr(hash_hmac('sha256', 'comment:' . $id, csrf_token()), 0, 16);
}
// 解析并验签 cookie；返回 [id => true]（保持记录顺序）。
function comment_mine_ids(): array
{
    $raw = (string)($_COOKIE['mono_mine_comment'] ?? '');
    $ids = [];
    foreach (explode(',', $raw) as $pair) {
        $parts = explode('.', $pair, 2);
        if (count($parts) !== 2 || !preg_match('/^[a-f0-9]{16}$/', $parts[1])) continue;
        $id = (int)$parts[0];
        if ($id > 0 && hash_equals(comment_mine_token($id), $parts[1])) $ids[$id] = true;
    }
    return $ids;
}
function comment_mine_write(array $ids): void
{
    if (headers_sent()) return;
    if (!$ids) {
        app_cookie('mono_mine_comment', '', time() - 3600);
        return;
    }
    $pairs = [];
    foreach (array_keys($ids) as $id) $pairs[] = $id . '.' . comment_mine_token((int)$id);
    app_cookie('mono_mine_comment', implode(',', $pairs), time() + 2592000);   // 30 天
}
// 提交待审评论后登记（只保留最近 20 条，避免 cookie 膨胀）。
function comment_mine_add(int $id): void
{
    $ids = comment_mine_ids();
    $ids[$id] = true;
    comment_mine_write(array_slice($ids, -20, null, true));
}
// 渲染「我的待审评论」区块：只查本文章的记录；status=0 展示（带标记），
// 已通过 / 已拒绝（status!=0）移出 cookie；属于其他文章的记录保持不变。
function comment_mine_html(int $post_id): string
{
    $ids = comment_mine_ids();
    if (!$ids) return '';
    $keys = array_map('intval', array_keys($ids));
    $rows = all('SELECT * FROM plugin_comment_comments WHERE post_id=? AND id IN (' . implode(',', $keys) . ') ORDER BY id ASC', [$post_id]);
    $seen = [];
    $keep = [];
    $items = '';
    foreach ($rows as $c) {
        $cid = (int)$c['id'];
        $seen[$cid] = true;
        if ((int)$c['status'] !== 0) continue;   // 审核已出结果：不再列为待审
        $keep[$cid] = true;
        $items .= comment_item_html($c, $post_id, 0, false, false, true);
    }
    $final = [];
    foreach (array_keys($ids) as $id) {
        if (!isset($seen[$id]) || isset($keep[$id])) $final[$id] = true;
    }
    if (array_diff_key($ids, $final)) comment_mine_write($final);   // 惰性清理
    if ($items === '') return '';
    return '<div class="comment-mine"><div class="comment-mine-note">以下是你提交的评论，正在审核中——通过后对所有人公开，目前仅你自己可见。</div>' . $items . '</div>';
}

// 渲染单条评论（顶层与楼中楼子回复共用）。$reply_id 为点击「回复」时的目标楼层（顶层楼 id）；
// $anchor 为 true 时输出 id="comment-item-N" 供跳转锚点定位；$pending 为待审条目（仅本人可见）。
function comment_item_html(array $c, int $post_id, int $reply_id, bool $nested, bool $anchor = false, bool $pending = false): string
{
    $author = (string)$c['author'];
    $html = '<div class="comment-item"' . ($anchor ? ' id="comment-item-' . (int)$c['id'] . '"' : '') . ' data-comment-author="' . h($author) . '">'
        . '<img class="avatar" src="' . h(avatar_url((string)($c['email'] ?? '') !== '' ? (string)$c['email'] : $author, 80)) . '" alt="">'
        . '<div class="comment-body"><div class="comment-head">'
        . '<span class="name">' . h($author) . '</span>'
        . '<span class="time">' . h(human_time((int)$c['created_at'])) . '</span>';
    if ($pending) $html .= '<span class="comment-mine-tag">审核中</span>';
    if (is_admin()) {
        // 楼中楼删除顶层时会连带其下回复，确认文案予以说明。
        $confirm = $nested && (int)($c['parent_id'] ?? 0) === 0 ? '删除这条评论？其下的回复将一并删除。' : '删除这条评论？';
        $html .= post_action_form(route_url('comment_delete', ['id' => (int)$c['id']]), '删除', ['post_id' => $post_id], 'btn sm danger', $confirm);
    }
    // 回复 @：任意访客/登录用户可点；楼中楼模式下 data-reply-id 指向所属楼层。待审条目尚未公开，不提供回复。
    if (!$pending) {
        $html .= '<button type="button" class="comment-reply-btn" data-reply="' . h($author) . '" data-reply-id="' . $reply_id . '">回复</button>';
    }
    $html .= '</div><div class="comment-text">' . comment_format((string)$c['content']) . '</div></div></div>';
    return $html;
}

// 渲染某篇文章的评论区（列表 + 表单）。详情页只调用一次。
function comment_render_section(string $value, array $ctx): string
{
    $post_id = (int)($ctx['post_id'] ?? 0);
    if ($post_id <= 0) return $value;
    if (setting('allow_comment', '1') !== '1') return $value;
    // 文章级开关：写文章页可单篇关闭评论（迁移前的旧数据缺列时视为允许）。
    if ((int)($ctx['post']['allow_comment'] ?? 1) !== 1) return $value;

    comment_upgrade_schema();   // 老库（<1.6.0）补 parent_id 列，幂等
    $cfg = comment_config();
    $size = max(1, (int)$cfg['per_page']);
    $total = (int)val('SELECT COUNT(*) FROM plugin_comment_comments WHERE post_id=? AND status=1', [$post_id]);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $page_url = fn(int $p): string => route_url('post', ['id' => $post_id, 'page' => $p]) . '#comment';

    // section 带 id="comment"：提交/删除跳转与分页链接都锚定到这里。
    $html = '<section class="comment-section card" id="comment" data-plugin-id="comment">';
    $html .= '<div class="card-title">评论（' . $total . '）</div>';
    if ($total === 0) {
        $html .= '<div class="comment-empty">还没有评论，来抢沙发吧。</div>';
    } elseif ($cfg['nested']) {
        // 楼中楼：只对顶层评论分页，子回复随所属楼层一并取出（回复与父楼不会被分页拆散）。
        $roots_total = (int)val('SELECT COUNT(*) FROM plugin_comment_comments WHERE post_id=? AND status=1 AND parent_id=0', [$post_id]);
        $page = $roots_total > 0 ? min($page, (int)ceil($roots_total / $size)) : 1;
        $roots = all('SELECT * FROM plugin_comment_comments WHERE post_id=? AND status=1 AND parent_id=0 ORDER BY created_at ASC, id ASC LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size), [$post_id]);
        // 子回复一次批查后按父楼层归组（id 均来自数据库，直接拼接安全）。
        $children = [];
        if ($roots) {
            $ids = implode(',', array_map(static fn(array $r): int => (int)$r['id'], $roots));
            foreach (all('SELECT * FROM plugin_comment_comments WHERE status=1 AND parent_id IN (' . $ids . ') ORDER BY created_at ASC, id ASC') as $c) {
                $children[(int)$c['parent_id']][] = $c;
            }
        }
        foreach ($roots as $c) {
            $cid = (int)$c['id'];
            $html .= '<div class="comment-thread">' . comment_item_html($c, $post_id, $cid, true, true);
            if (!empty($children[$cid])) {
                $html .= '<div class="comment-children">';
                foreach ($children[$cid] as $cc) $html .= comment_item_html($cc, $post_id, $cid, true);
                $html .= '</div>';
            }
            $html .= '</div>';
        }
        if ($roots_total > 0) $html .= paginate($roots_total, $page, $size, $page_url);
    } else {
        // 平铺：全部评论逐条列出（原行为）。
        $page = min($page, (int)ceil($total / $size));
        $comments = all('SELECT * FROM plugin_comment_comments WHERE post_id=? AND status=1 ORDER BY created_at ASC, id ASC LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size), [$post_id]);
        foreach ($comments as $c) $html .= comment_item_html($c, $post_id, (int)$c['id'], false);
        $html .= paginate($total, $page, $size, $page_url);
    }

    // 我的待审评论（仅提交者本机可见；通过 / 拒绝后自动移出并清理记录）。
    $html .= comment_mine_html($post_id);

    // 发表表单。
    $me = me();
    if ($cfg['require_login'] && !$me) {
        $html .= '<div class="comment-pending">请<a href="' . h(route_url('login')) . '">登录</a>后发表评论。</div>';
    } else {
        $html .= '<form class="comment-form" method="post" action="' . h(route_url('comment_submit')) . '" style="margin-top:16px">' . form_token()
            . '<input type="hidden" name="post_id" value="' . $post_id . '">'
            . ($cfg['nested'] ? '<input type="hidden" name="parent_id" value="0">' : '');
        if (!$me) {
            // 游客身份记忆（localStorage，与动态评论共用同一对 key）：填过一次即自动预填，有记忆时折叠为一行摘要（可展开修改）。
            $html .= '<div class="comment-guest" data-guest-fields>'
                . '<div class="form-grid-2">' . input('昵称', 'author', '', 'text', true) . input('邮箱', 'email', '', 'email', false, '你的邮箱不会公开') . '</div>'
                . '<div class="comment-guest-remember">以 <b class="comment-guest-remember-name"></b> 的身份评论<button type="button" class="comment-guest-edit" data-guest-edit>修改</button></div>'
                . '</div>';
        }
        $html .= '<div class="comment-editor">' . form_field_caption('评论内容', '')
            . ($cfg['nested'] ? '<div class="comment-reply-hint" hidden>正在回复 <b class="comment-reply-hint-name"></b><button type="button" class="comment-reply-cancel" data-reply-cancel>取消</button></div>' : '')
            . '<div class="comment-editor-box">'
            . '<div class="comment-toolbar">'
            . '<button type="button" class="comment-tbtn comment-emoji-btn" title="表情" aria-label="表情" aria-haspopup="true" aria-expanded="false">' . comment_icon('smile') . '</button>'
            . '<div class="comment-emoji-panel">' . comment_emoji_panel() . '</div>'
            . '<span class="comment-tbtn-sep"></span>'
            . comment_tbtn('bold', '加粗', 'data-cmd="bold"')
            . comment_tbtn('italic', '斜体', 'data-cmd="italic"')
            . comment_tbtn('code', '行内代码', 'data-cmd="code"')
            . comment_tbtn('link', '链接', 'data-cmd="link"')
            . '</div>'
            . '<textarea name="content" required maxlength="5000" placeholder="说点什么吧…"></textarea>'
            . '</div></div>'
            . '<button class="btn" type="submit">发表评论</button></form>';
    }
    return $value . $html . '</section>';
}

// 常用表情面板（点击在评论框光标处插入）。5×8=40，均为单码位或带 VS16 的常见表情。
function comment_emoji_panel(): string
{
    $emojis = [
        '😀', '😄', '😂', '🤣', '😊', '😍', '🥰', '😘',
        '🤔', '😐', '😴', '🤤', '😭', '😡', '🤯', '🥳',
        '😎', '🤗', '🙄', '😅', '👍', '👎', '👏', '🙏',
        '💪', '👌', '👀', '🤝', '❤️', '💔', '💯', '✨',
        '🎉', '🎁', '⭐', '🔥', '☕', '🍺', '🌈', '🌹',
    ];
    $html = '';
    foreach ($emojis as $e) $html .= '<button type="button" class="comment-emoji-item" data-emoji="' . h($e) . '" title="' . h($e) . '">' . $e . '</button>';
    return $html;
}

/**
 * 评论内容轻量行内格式化（与工具栏按钮一一对应）。
 * 安全前提：先整体 h() 转义，再按白名单还原标记；链接仅允许 http/https，并带 noopener nofollow。
 * 支持：`code`、**粗体**、*斜体*、[文本](链接)、裸链接自动可点、@提及高亮。
 */
function comment_format(string $text): string
{
    $s = h($text);
    $keep = [];
    // 已构造好的 HTML 先用占位符保护，避免被后续规则（尤其裸链接）二次改写。
    $stash = function (string $html) use (&$keep): string {
        $keep[] = $html;
        return "\x00K" . (count($keep) - 1) . "\x00";
    };
    $link = static fn(string $url, string $label): string => '<a href="' . $url . '" target="_blank" rel="noopener nofollow">' . $label . '</a>';

    // 行内代码
    $s = preg_replace_callback('/`([^`\n]+)`/', static fn($m) => $stash('<code>' . $m[1] . '</code>'), $s) ?? $s;
    // Markdown 链接 [文本](http…)
    $s = preg_replace_callback('/\[([^\]\n]*)\]\((https?:\/\/[^\s)]+)\)/', function ($m) use ($stash, $link) {
        $url = str_replace(['"', "'", ' '], '', $m[2]);
        return $stash($link($url, $m[1] !== '' ? $m[1] : $url));
    }, $s) ?? $s;
    // 裸链接自动可点：仅匹配 URL 合法字符（白名单），避免中文紧贴链接时把整句后文吞进 URL；结尾标点不计入链接。
    $s = preg_replace_callback('/https?:\/\/[A-Za-z0-9\-._~%:\/?#\[\]@!$&*+,;=()\x27]+/', function ($m) use ($stash, $link) {
        if (!preg_match('/^(.*?)([.,;:!?)\]}\'\"]+)$/', $m[0], $mm)) {
            return $stash($link($m[0], $m[0]));
        }
        return $mm[1] !== '' ? $stash($link($mm[1], $mm[1])) . $mm[2] : $m[0];
    }, $s) ?? $s;
    // 粗体 / 斜体
    $s = preg_replace('/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $s) ?? $s;
    $s = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $s) ?? $s;
    // @提及高亮（仅视觉，不构成链接）
    $s = preg_replace('/(^|\s)@([^\s@<]{1,40})/u', '$1<span class="comment-mention">@$2</span>', $s) ?? $s;

    return preg_replace_callback('/\x00K(\d+)\x00/', static fn($m) => $keep[(int)$m[1]] ?? '', $s) ?? $s;
}

// 评论编辑器脚本：表情面板（选完自动关闭）、图标工具栏（粗/斜/代码/链接）、@回复、Ctrl/Cmd+Enter 提交。
function comment_js(): string
{
    return <<<'JS'
(function () {
  'use strict';
  // 在光标处插入文本（有选区则替换选区）。
  function insertAt(ta, text) {
    var s = ta.selectionStart, v = ta.value;
    ta.value = v.slice(0, s) + text + v.slice(ta.selectionEnd);
    ta.focus();
    ta.selectionStart = ta.selectionEnd = s + text.length;
  }
  // 用 before/after 包裹选区；无选区时插入占位文本并选中，直接打字即可覆盖。
  function surround(ta, before, after, placeholder) {
    var s = ta.selectionStart, v = ta.value;
    var sel = v.slice(s, ta.selectionEnd) || placeholder || '';
    ta.value = v.slice(0, s) + before + sel + after + v.slice(ta.selectionEnd);
    ta.focus();
    ta.selectionStart = s + before.length;
    ta.selectionEnd = s + before.length + sel.length;
  }
  function applyCmd(ta, cmd) {
    if (cmd === 'bold') return surround(ta, '**', '**', '粗体');
    if (cmd === 'italic') return surround(ta, '*', '*', '斜体');
    if (cmd === 'code') return surround(ta, '`', '`', '代码');
    if (cmd === 'link') {
      var s = ta.selectionStart, v = ta.value;
      var sel = v.slice(s, ta.selectionEnd).trim();
      var isUrl = /^https?:\/\/\S+$/i.test(sel);
      var label = isUrl ? '链接' : (sel || '链接文字');
      var text = '[' + label + '](' + (isUrl ? sel : 'https://') + ')';
      ta.value = v.slice(0, s) + text + v.slice(ta.selectionEnd);
      ta.focus();
      // 选中待改写的部分：已给网址则选标签文字，否则选网址。
      ta.selectionStart = isUrl ? s + 1 : s + label.length + 3;
      ta.selectionEnd = isUrl ? s + 1 + label.length : s + text.length - 1;
    }
  }
  function bind(editor) {
    var ta = editor.querySelector('textarea');
    if (!ta) return;
    var btn = editor.querySelector('.comment-emoji-btn');
    var panel = editor.querySelector('.comment-emoji-panel');
    function closePanel() {
      if (!panel) return;
      panel.classList.remove('open');
      if (btn) btn.setAttribute('aria-expanded', 'false');
    }
    // 工具栏：格式按钮与表情项统一委派（图标为 SVG，点击目标可能是子元素）。
    editor.addEventListener('click', function (e) {
      var near = e.target.closest ? e.target.closest.bind(e.target) : null;
      if (!near) return;
      var cmd = near('[data-cmd]');
      if (cmd) { applyCmd(ta, cmd.getAttribute('data-cmd')); return; }
      var item = near('.comment-emoji-item');
      if (item) {
        insertAt(ta, item.getAttribute('data-emoji') || '');
        closePanel();   // 选完即关，不再遮挡正文
      }
    });
    if (btn && panel) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var open = panel.classList.toggle('open');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
      document.addEventListener('click', function (e) {
        if (!panel.classList.contains('open')) return;
        if (panel.contains(e.target) || btn.contains(e.target)) return;
        closePanel();
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closePanel();
      });
    }
    ta.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        var form = ta.closest('form');
        if (form) { if (form.requestSubmit) form.requestSubmit(); else form.submit(); }
      }
    });
  }
  // 回复 @：全局委派，点击评论条目的「回复」按钮 → 将「@昵称 」追加到评论框末尾并聚焦。
  // 楼中楼模式下同时把回复目标写入表单（子回复按钮的 data-reply-id 指向所属楼层），并显示可取消的提示条。
  function bindReplyButtons() {
    document.addEventListener('click', function (e) {
      // 取消回复：恢复为顶层评论。
      var cancel = e.target.closest ? e.target.closest('[data-reply-cancel]') : null;
      if (cancel) {
        var cform = cancel.closest('form');
        if (cform) {
          var chid = cform.querySelector('input[name="parent_id"]');
          if (chid) chid.value = '0';
          var chint = cform.querySelector('.comment-reply-hint');
          if (chint) chint.hidden = true;
        }
        return;
      }
      var btn = e.target.closest ? e.target.closest('.comment-reply-btn') : null;
      if (!btn) return;
      var section = btn.closest('.comment-section');
      if (!section) return;
      var ta = section.querySelector('.comment-editor-box textarea');
      if (!ta) return;
      var name = btn.getAttribute('data-reply') || '';
      if (!name) return;
      // 楼中楼模式（表单含 parent_id 隐藏域）：记录回复目标并显示提示条。
      var form = ta.closest('form');
      var pid = btn.getAttribute('data-reply-id') || '';
      if (form && pid) {
        var hidden = form.querySelector('input[name="parent_id"]');
        if (hidden) {
          hidden.value = pid;
          var hint = form.querySelector('.comment-reply-hint');
          var hintName = form.querySelector('.comment-reply-hint-name');
          if (hint) hint.hidden = false;
          if (hintName) hintName.textContent = '@' + name;
        }
      }
      var mention = '@' + name + ' ';
      var v = ta.value;
      // 避免重复插入同一 mention（若已在末尾则仅聚焦）。
      if (v.slice(-mention.length) !== mention) {
        ta.value = (v && !/\s$/.test(v) ? v + ' ' : v) + mention;
      }
      ta.focus();
      ta.selectionStart = ta.selectionEnd = ta.value.length;
      ta.dispatchEvent(new Event('input', { bubbles: true }));
      // 滚动到评论框，长页面下体验更好。
      if (ta.scrollIntoView) ta.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
  }
  // 游客身份记忆（localStorage，与动态评论共用同一对 key，键名 mono_guest_author / mono_guest_email）：
  // 进页预填（不覆盖已有值），有昵称记忆即折叠为一行摘要，点「修改」展开；提交时写入本次填写。
  function guestGet(k) { try { return localStorage.getItem('mono_guest_' + k) || ''; } catch (err) { return ''; } }
  function guestSet(k, v) { try { localStorage.setItem('mono_guest_' + k, v); } catch (err) {} }
  function bindGuestFields() {
    var wraps = document.querySelectorAll('[data-guest-fields]');
    for (var i = 0; i < wraps.length; i++) bindGuest(wraps[i]);
  }
  function bindGuest(wrap) {
    var form = wrap.closest('form');
    if (!form) return;
    var author = form.querySelector('input[name="author"]');
    if (!author) return;
    var email = form.querySelector('input[name="email"]');
    var a = guestGet('author');
    if (a && !author.value) author.value = a;
    if (email) {
      var em = guestGet('email');
      if (em && !email.value) email.value = em;
    }
    var remember = wrap.querySelector('.comment-guest-remember');
    var nameEl = wrap.querySelector('.comment-guest-remember-name');
    if (a && remember) {
      wrap.classList.add('collapsed');
      if (nameEl) nameEl.textContent = a;
    }
    var edit = wrap.querySelector('[data-guest-edit]');
    if (edit) edit.addEventListener('click', function () {
      wrap.classList.remove('collapsed');
      author.focus();
    });
    form.addEventListener('submit', function () {
      var av = author.value.trim();
      if (av) guestSet('author', av);
      if (email) {
        var ev = email.value.trim();
        if (ev) guestSet('email', ev);
      }
    });
  }
  function init() {
    var editors = document.querySelectorAll('.comment-editor');
    for (var i = 0; i < editors.length; i++) bind(editors[i]);
    bindReplyButtons();
    bindGuestFields();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
}());
JS;
}

// 提交评论。
function comment_submit(array $plugin): void
{
    require_post();
    comment_upgrade_schema();   // 老库（<1.6.0）补 parent_id 列，幂等
    $post_id = (int)($_POST['post_id'] ?? 0);
    $post = row('app_posts', 'id', $post_id);
    if (!$post || (int)$post['status'] !== 1) err('文章不存在');
    if (setting('allow_comment', '1') !== '1') err('评论已关闭');
    if ((int)($post['allow_comment'] ?? 1) !== 1) err('该文章已关闭评论');

    $cfg = comment_config();
    $me = me();
    if ($cfg['require_login']) need_login();

    $content = trim((string)($_POST['content'] ?? ''));
    $back = route_url('post', ['id' => $post_id]) . '#comment';
    if ($content === '') { set_flash('评论内容不能为空', 'error'); go($back); }
    if (mb_strlen($content) > 5000) { set_flash('评论内容过长', 'error'); go($back); }

    if ($me) {
        $author = (string)$me['nickname'];
        $email = (string)$me['email'];
    } else {
        $author = post('author', 40);
        $email = post('email', 150);
        if ($author === '') { set_flash('请填写昵称', 'error'); go($back); }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { set_flash('邮箱格式不正确', 'error'); go($back); }
    }

    // 楼中楼：解析回复目标——必须是本文的顶层评论；目标是子回复时归位到所属楼层；非法则视为顶层评论。
    $parent_id = (int)($_POST['parent_id'] ?? 0);
    if ($parent_id > 0) {
        $parent = row('plugin_comment_comments', 'id', $parent_id);
        if (!$parent || (int)$parent['post_id'] !== $post_id) {
            $parent_id = 0;
        } elseif ((int)$parent['parent_id'] > 0) {
            $parent_id = (int)$parent['parent_id'];
        }
    }

    // 简单防刷：同 IP 60 秒内只能发一条。
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $recent = one('SELECT id FROM plugin_comment_comments WHERE created_at>? ORDER BY id DESC LIMIT 1', [now() - 60]);
    // 先审后显开启时游客评论进入待审；管理员（博主）的评论与回复视为已审，直接显示。
    $status = $cfg['moderate'] && !is_admin() ? 0 : 1;

    q('INSERT INTO plugin_comment_comments(post_id,parent_id,author,email,content,status,created_at) VALUES(?,?,?,?,?,?,?)',
        [$post_id, $parent_id, $author, $email, $content, $status, now()]);
    if ($status === 0) comment_mine_add(app_db_last_insert_id('plugin_comment_comments'));

    set_flash($status === 1 ? '评论发表成功' : '评论已提交，审核中（仅你自己可见）');
    // 新内容直接跳到能看到自己的位置：楼中楼的回复定位到所属楼层页（锚点直达楼层），其余跳到最后一页。
    $back = route_url('post', ['id' => $post_id]) . '#comment';
    if ($status === 1) {
        $size = max(1, (int)$cfg['per_page']);
        if ($cfg['nested'] && $parent_id > 0) {
            $pos = (int)val('SELECT COUNT(*) FROM plugin_comment_comments WHERE post_id=? AND status=1 AND parent_id=0 AND id<=?', [$post_id, $parent_id]);
            $last = max(1, (int)ceil($pos / $size));
            $back = route_url('post', ['id' => $post_id] + ($last > 1 ? ['page' => $last] : [])) . '#comment-item-' . $parent_id;
        } else {
            $count = (int)val('SELECT COUNT(*) FROM plugin_comment_comments WHERE post_id=? AND status=1' . ($cfg['nested'] ? ' AND parent_id=0' : ''), [$post_id]);
            $last = max(1, (int)ceil($count / $size));
            if ($last > 1) $back = route_url('post', ['id' => $post_id, 'page' => $last]) . '#comment';
        }
    }
    go($back);
}

// 删除评论（管理员）。楼中楼模式下删除顶层楼层时，其下的回复一并删除，避免留下孤儿。
function comment_delete(array $plugin): void
{
    need_admin();
    require_post();
    $id = (int)($_GET['id'] ?? 0);
    $post_id = (int)($_POST['post_id'] ?? 0);
    if (comment_config()['nested']) q('DELETE FROM plugin_comment_comments WHERE id=? OR parent_id=?', [$id, $id]);
    else q('DELETE FROM plugin_comment_comments WHERE id=?', [$id]);
    set_flash('评论已删除');
    go($post_id > 0 ? route_url('post', ['id' => $post_id]) : admin_url(['tab' => 'plugins', 'view' => 'comment']));
}

// 后台「评论」标签：启用后出现在后台导航，直达分页审核列表（与 admin_tabs 子页共用回调，渲染分支见 comment_admin）。
function comment_admin_tabs(array $tabs, array $ctx): array
{
    $tabs['comment'] = '评论';
    return $tabs;
}

// 后台评论管理页（双入口共用）：
// - 「评论」标签（?a=admin&tab=comment）：分页审核列表，待审在前，行内「通过 / 删除」。
// - 「插件 → 评论 → 配置」（?a=admin&tab=plugins&view=comment）：只显示插件设置，不再重复显示列表。
function comment_admin(array $plugin): string
{
    comment_upgrade_schema();   // 老库（<1.6.0）补 parent_id 列，幂等
    $from_tab = (string)($_GET['tab'] ?? '') === 'comment';
    $back = $from_tab ? admin_url(['tab' => 'comment']) : admin_url(['tab' => 'plugins', 'view' => 'comment']);
    if (is_post_request()) {
        $action = (string)($_POST['comment_admin_action'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if ($action === 'approve') { q('UPDATE plugin_comment_comments SET status=1 WHERE id=?', [$id]); set_flash('评论已通过'); }
        elseif ($action === 'delete') {
            // 楼中楼模式下删除顶层楼层时，其下的回复一并删除。
            if (comment_config()['nested']) q('DELETE FROM plugin_comment_comments WHERE id=? OR parent_id=?', [$id, $id]);
            else q('DELETE FROM plugin_comment_comments WHERE id=?', [$id]);
            set_flash('评论已删除');
        }
        elseif ($action === 'save') {
            plugin_save_config('comment', [
                'require_login' => (int)($_POST['require_login'] ?? 0) === 1 ? 1 : 0,
                'moderate' => (int)($_POST['moderate'] ?? 0) === 1 ? 1 : 0,
                'nested' => (int)($_POST['nested'] ?? 0) === 1 ? 1 : 0,
                'per_page' => (string)min(100, max(5, (int)($_POST['per_page'] ?? 50))),
            ]);
            set_flash('评论设置已保存');
        }
        // 处理完回到当前入口：后台「评论」标签，或「插件 → 评论 → 配置」。
        go($back);
    }

    // 「评论」标签：分页审核列表（20 条/页，待审优先）。
    if ($from_tab) {
        $page = current_page();
        $per = 20;
        $total = (int)val('SELECT COUNT(*) FROM plugin_comment_comments');
        $comments = all('SELECT c.*, p.title FROM plugin_comment_comments c LEFT JOIN app_posts p ON p.id=c.post_id ORDER BY c.status ASC, c.created_at DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
        $html = '<div class="btn-row" style="justify-content:flex-end;margin-bottom:10px">'
            . '<a class="btn sm ghost" href="' . h(admin_url(['tab' => 'plugins', 'view' => 'comment'])) . '">评论设置</a></div>';
        if (!$comments) return $html . '<p style="color:var(--text-muted)">还没有评论。</p>';
        $html .= '<table class="list"><thead><tr><th>作者</th><th>内容</th><th>文章</th><th>状态</th><th>时间</th><th class="actions">操作</th></tr></thead><tbody>';
        foreach ($comments as $c) {
            $html .= '<tr><td>' . h((string)$c['author']) . '</td>'
                . '<td style="max-width:280px">' . ((int)($c['parent_id'] ?? 0) > 0 ? '<span class="badge">回复</span> ' : '') . h(cut((string)$c['content'], 40)) . '</td>'
                . '<td><a href="' . h(route_url('post', ['id' => (int)$c['post_id']])) . '">' . h(cut((string)($c['title'] ?? '—'), 16)) . '</a></td>'
                . '<td>' . ((int)$c['status'] === 1 ? '<span class="badge">已显示</span>' : '<span class="badge draft">待审</span>') . '</td>'
                . '<td style="color:var(--text-subtle)">' . h(date('Y-m-d H:i', (int)$c['created_at'])) . '</td>'
                . '<td class="actions"><div class="btn-row" style="justify-content:flex-end">';
            if ((int)$c['status'] !== 1) {
                $html .= '<form method="post" style="display:inline">' . form_token() . '<input type="hidden" name="admin_action" value="noop"><input type="hidden" name="comment_admin_action" value="approve"><input type="hidden" name="id" value="' . (int)$c['id'] . '"><button class="btn sm" type="submit">通过</button></form>';
            }
            $html .= '<form method="post" style="display:inline" data-confirm="删除这条评论？">' . form_token() . '<input type="hidden" name="admin_action" value="noop"><input type="hidden" name="comment_admin_action" value="delete"><input type="hidden" name="id" value="' . (int)$c['id'] . '"><button class="btn sm danger" type="submit">删除</button></form>';
            $html .= '</div></td></tr>';
        }
        $html .= '</tbody></table>';
        return $html . paginate($total, $page, $per, fn(int $p): string => admin_url(['tab' => 'comment', 'page' => $p]));
    }

    // 「插件 → 评论 → 配置」：仅插件设置；列表与审核在「评论」标签。
    $cfg = comment_config();
    $html = '<form method="post" style="margin-bottom:20px">' . form_token()
        . '<input type="hidden" name="admin_action" value="noop"><input type="hidden" name="comment_admin_action" value="save">'
        . checkbox('登录后才能评论', 'require_login', $cfg['require_login'])
        . checkbox('评论先审后显', 'moderate', $cfg['moderate'])
        . checkbox('楼中楼模式', 'nested', $cfg['nested'], '回复嵌套显示在对应评论下方；关闭则为平铺列表（@回复）')
        . input('评论区每页条数', 'per_page', (string)$cfg['per_page'], 'number', false, '5-100，评论区分页的每页条数')
        . '<div class="btn-row"><button class="btn" type="submit">保存设置</button></div></form>'
        . '<div class="note" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">'
        . '<span>评论列表与「通过 / 删除」审核操作在后台顶部「评论」标签，本页只保留插件设置。</span>'
        . '<a class="btn sm ghost" href="' . h(admin_url(['tab' => 'comment'])) . '">前往评论管理</a></div>';
    return $html;
}

return [
    'id' => 'comment',
    'name' => '评论',
    'version' => '1.8.0',
    'description' => '为文章开启评论功能，支持游客或登录后发表、先审后显（管理员评论与回复免审）与后台分页审核（后台导航「评论」标签直达列表，插件配置页只保留设置），可选楼中楼模式（回复嵌套在对应评论下方）；游客昵称 / 邮箱填过一次即自动预填，有记忆时折叠为一行摘要；待审评论仅提交者本人可见（标「审核中」，通过 / 拒绝后自动隐藏）；可在写文章页按文章单独关闭评论；评论框带图标工具栏（表情/加粗/斜体/代码/链接）与 @回复，内容支持轻量行内格式。',
    'author' => 'Mono',
    'assets' => ['css' => 'comment_css', 'js' => 'comment_js'],
    'hooks' => ['post.content_after' => 'comment_render_section', 'admin.tabs' => 'comment_admin_tabs'],
    'routes' => ['comment_submit' => 'comment_submit', 'comment_delete' => 'comment_delete'],
    'admin_tabs' => ['comment' => 'comment_admin'],
    'install' => 'comment_install',
    'uninstall' => 'comment_uninstall',
];
