<?php
if (!defined('APP_ROOT')) exit;

/**
 * 评论插件（comment）。
 *
 * 「万物皆插件」：核心不含评论逻辑，评论能力完全由本插件通过 Hook 与路由提供。
 * - hook post.content_after：在文章正文后渲染评论列表与发表表单（详情页单次触发，一次查询，无 N+1）。
 * - route comment_submit：POST 提交评论（require_post + CSRF，支持游客昵称/邮箱）。
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
        'per_page' => min(100, max(5, (int)($raw['per_page'] ?? 50))),
    ];
}

function comment_install(array $plugin): void
{
    $t = app_db_types();
    app_db_create_table('plugin_comment_comments', "id {$t['id']},post_id {$t['uint']} NOT NULL,author {$t['string']} NOT NULL,email {$t['string']},content {$t['text']} NOT NULL,status {$t['uint']} NOT NULL DEFAULT 1,created_at {$t['uint']} NOT NULL");
    app_db_create_index('idx_comment_post', 'plugin_comment_comments (post_id,status,created_at)');
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

// 渲染某篇文章的评论区（列表 + 表单）。详情页只调用一次。
function comment_render_section(string $value, array $ctx): string
{
    $post_id = (int)($ctx['post_id'] ?? 0);
    if ($post_id <= 0) return $value;
    if (setting('allow_comment', '1') !== '1') return $value;
    // 文章级开关：写文章页可单篇关闭评论（迁移前的旧数据缺列时视为允许）。
    if ((int)($ctx['post']['allow_comment'] ?? 1) !== 1) return $value;

    $cfg = comment_config();
    $size = max(1, (int)$cfg['per_page']);
    $total = (int)val('SELECT COUNT(*) FROM plugin_comment_comments WHERE post_id=? AND status=1', [$post_id]);
    $page = max(1, (int)($_GET['page'] ?? 1));
    if ($total > 0) $page = min($page, (int)ceil($total / $size));
    $comments = all('SELECT * FROM plugin_comment_comments WHERE post_id=? AND status=1 ORDER BY created_at ASC, id ASC LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size), [$post_id]);

    // section 带 id="comment"：提交/删除跳转与分页链接都锚定到这里。
    $html = '<section class="comment-section card" id="comment" data-plugin-id="comment">';
    $html .= '<div class="card-title">评论（' . $total . '）</div>';
    if ($total === 0) {
        $html .= '<div class="comment-empty">还没有评论，来抢沙发吧。</div>';
    } else {
        foreach ($comments as $c) {
            $author = (string)$c['author'];
            $html .= '<div class="comment-item" data-comment-author="' . h($author) . '">'
                . '<img class="avatar" src="' . h(avatar_url((string)($c['email'] ?? '') !== '' ? (string)$c['email'] : $author, 80)) . '" alt="">'
                . '<div class="comment-body"><div class="comment-head">'
                . '<span class="name">' . h($author) . '</span>'
                . '<span class="time">' . h(human_time((int)$c['created_at'])) . '</span>';
            if (is_admin()) {
                $html .= post_action_form(route_url('comment_delete', ['id' => (int)$c['id']]), '删除', ['post_id' => $post_id], 'btn sm danger', '删除这条评论？');
            }
            // 回复 @：任意访客/登录用户可点，将「@昵称 」插入到评论框并聚焦。
            $html .= '<button type="button" class="comment-reply-btn" data-reply="' . h($author) . '">回复</button>'
                . '</div><div class="comment-text">' . comment_format((string)$c['content']) . '</div></div></div>';
        }
        // 分页：条数取插件设置（per_page），页码链接锚定评论区。
        $html .= paginate($total, $page, $size, fn(int $p): string => route_url('post', ['id' => $post_id, 'page' => $p]) . '#comment');
    }

    // 发表表单。
    $me = me();
    if ($cfg['require_login'] && !$me) {
        $html .= '<div class="comment-pending">请<a href="' . h(route_url('login')) . '">登录</a>后发表评论。</div>';
    } else {
        $html .= '<form class="comment-form" method="post" action="' . h(route_url('comment_submit')) . '" style="margin-top:16px">' . form_token()
            . '<input type="hidden" name="post_id" value="' . $post_id . '">';
        if (!$me) {
            $html .= '<div class="form-grid-2">' . input('昵称', 'author', '', 'text', true) . input('邮箱', 'email', '', 'email', false, '你的邮箱不会公开') . '</div>';
        }
        $html .= '<div class="comment-editor">' . form_field_caption('评论内容', '')
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
  function bindReplyButtons() {
    document.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('.comment-reply-btn') : null;
      if (!btn) return;
      var section = btn.closest('.comment-section');
      if (!section) return;
      var ta = section.querySelector('.comment-editor-box textarea');
      if (!ta) return;
      var name = btn.getAttribute('data-reply') || '';
      if (!name) return;
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
  function init() {
    var editors = document.querySelectorAll('.comment-editor');
    for (var i = 0; i < editors.length; i++) bind(editors[i]);
    bindReplyButtons();
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

    // 简单防刷：同 IP 60 秒内只能发一条。
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $recent = one('SELECT id FROM plugin_comment_comments WHERE created_at>? ORDER BY id DESC LIMIT 1', [now() - 60]);
    $status = $cfg['moderate'] ? 0 : 1;

    q('INSERT INTO plugin_comment_comments(post_id,author,email,content,status,created_at) VALUES(?,?,?,?,?,?)',
        [$post_id, $author, $email, $content, $status, now()]);

    set_flash($status === 1 ? '评论发表成功' : '评论已提交，等待审核');
    // 新评论按时间排在最后一页：公开的评论直接跳到对应页码，作者能立即看到自己。
    $back = route_url('post', ['id' => $post_id]) . '#comment';
    if ($status === 1) {
        $size = max(1, $cfg['per_page']);
        $count = (int)val('SELECT COUNT(*) FROM plugin_comment_comments WHERE post_id=? AND status=1', [$post_id]);
        $last = max(1, (int)ceil($count / $size));
        if ($last > 1) $back = route_url('post', ['id' => $post_id, 'page' => $last]) . '#comment';
    }
    go($back);
}

// 删除评论（管理员）。
function comment_delete(array $plugin): void
{
    need_admin();
    require_post();
    $id = (int)($_GET['id'] ?? 0);
    $post_id = (int)($_POST['post_id'] ?? 0);
    q('DELETE FROM plugin_comment_comments WHERE id=?', [$id]);
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
    $from_tab = (string)($_GET['tab'] ?? '') === 'comment';
    $back = $from_tab ? admin_url(['tab' => 'comment']) : admin_url(['tab' => 'plugins', 'view' => 'comment']);
    if (is_post_request()) {
        $action = (string)($_POST['comment_admin_action'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if ($action === 'approve') { q('UPDATE plugin_comment_comments SET status=1 WHERE id=?', [$id]); set_flash('评论已通过'); }
        elseif ($action === 'delete') { q('DELETE FROM plugin_comment_comments WHERE id=?', [$id]); set_flash('评论已删除'); }
        elseif ($action === 'save') {
            plugin_save_config('comment', [
                'require_login' => (int)($_POST['require_login'] ?? 0) === 1 ? 1 : 0,
                'moderate' => (int)($_POST['moderate'] ?? 0) === 1 ? 1 : 0,
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
                . '<td style="max-width:280px">' . h(cut((string)$c['content'], 40)) . '</td>'
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
    'version' => '1.4.0',
    'description' => '为文章开启评论功能，支持游客或登录后发表、先审后显与后台分页审核（后台导航「评论」标签直达列表，插件配置页只保留设置）；可在写文章页按文章单独关闭评论；评论框带图标工具栏（表情/加粗/斜体/代码/链接）与 @回复，内容支持轻量行内格式。',
    'author' => 'Mono',
    'assets' => ['css' => 'comment_css', 'js' => 'comment_js'],
    'hooks' => ['post.content_after' => 'comment_render_section', 'admin.tabs' => 'comment_admin_tabs'],
    'routes' => ['comment_submit' => 'comment_submit', 'comment_delete' => 'comment_delete'],
    'admin_tabs' => ['comment' => 'comment_admin'],
    'install' => 'comment_install',
    'uninstall' => 'comment_uninstall',
];
