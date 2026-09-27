<?php
if (!defined('APP_ROOT')) exit;

/**
 * 文章封面插件（cover）。
 *
 * 列表缩略图三级来源：逐篇自定义封面（写文章页填写） > 正文第一张图片（自动） > 插件配置的默认封面图。
 *
 * 数据流（遵循「占位符回填」思想，列表循环内零查询）：
 * 1. 收集：post.list.after_render 在每篇文章的 ctx 里取 post_id 与正文首图（纯字符串解析，不查库）；
 * 2. 回填：page.before_render 整页一次 IN 查询取回全部自定义封面，将缩略图 <div> 用正则插入对应
 *    <article class="post-item" data-post-id="N"> 内的首位；浮动布局 + CSS 实现左/右/左右交替。
 *
 * 写文章页的「封面图」输入框同样由 page.before_render 在表单锚点前插入（零 JS，随表单提交）；
 * 保存由 post.after_save 从 $_POST['plugin_cover_url'] 落库（表 plugin_cover_covers），留空即删除。
 */

function cover_config(): array
{
    $raw = plugin_config('cover', []);
    $pos = (string)($raw['position'] ?? 'left');
    if (!in_array($pos, ['left', 'right', 'alternate'], true)) $pos = 'left';
    return [
        'position' => $pos,
        'width' => min(400, max(60, (int)($raw['width'] ?? 150))),
        'default' => cover_valid_image((string)($raw['default'] ?? '')),
    ];
}

// 封面地址白名单：http(s):// 或站内绝对路径，禁止空白/引号/尖括号。
function cover_valid_image(string $url): string
{
    $url = trim($url);
    if ($url === '' || mb_strlen($url) > 250) return '';
    if (preg_match('#^(https?://|/)#i', $url) !== 1) return '';
    return preg_match('/[\s"\'<>]/', $url) === 1 ? '' : $url;
}

// 从 Markdown 原文提取第一张图片：![alt](url) 与 <img src> 两种写法取位置靠前者。
function cover_first_image(string $content): string
{
    $best = '';
    $best_pos = PHP_INT_MAX;
    foreach ([
        '/!\[[^\]]*\]\(\s*<?([^\s)>]+)>?/u',
        '/<img[^>]+?src\s*=\s*["\']([^"\']+)["\'][^>]*>/iu',
    ] as $pattern) {
        if (preg_match($pattern, $content, $m, PREG_OFFSET_CAPTURE) === 1 && isset($m[1][0])) {
            $pos = (int)$m[0][1];
            if ($pos < $best_pos) {
                $best_pos = $pos;
                $best = (string)$m[1][0];
            }
        }
    }
    return cover_valid_image($best);
}

// 收集（列表循环内，零查询）：登记 post_id 与正文首图。
function cover_collect(string $value, array $ctx): string
{
    $post = $ctx['post'] ?? null;
    $pid = is_array($post) ? (int)($post['id'] ?? 0) : 0;
    if ($pid > 0) $GLOBALS['__cover_pending'][$pid] = cover_first_image((string)($post['content'] ?? ''));
    return $value;
}

// 写文章 / 编辑页：在保存按钮行之前插入封面图输入框（编辑时回显当前自定义封面）。
function cover_inject_editor_field(string $html): string
{
    $current = '';
    $pid = (int)($_GET['id'] ?? 0);
    if ($pid > 0) {
        $row = one('SELECT image FROM plugin_cover_covers WHERE post_id=?', [$pid]);
        if ($row) $current = (string)$row['image'];
    }
    $field = '<div class="field-block">'
        . input('封面图', 'plugin_cover_url', $current, 'text', false, '留空自动取正文首图；可填图片链接或站内路径')
        . '</div>';
    $out = str_replace('<div class="btn-row write-actions">', $field . '<div class="btn-row write-actions">', $html, $n);
    return $n > 0 ? $out : $html;
}

// 列表回填：整页一次 IN 查询 + 正则把缩略图插入每篇文章首位。
function cover_inject_list(string $html): string
{
    $pending = $GLOBALS['__cover_pending'] ?? [];
    if (!$pending) return $html;
    $cfg = cover_config();
    $ids = array_values(array_unique(array_map('intval', array_keys($pending))));
    if (!$ids) return $html;
    $custom = [];
    $rows = all('SELECT post_id,image FROM plugin_cover_covers WHERE post_id IN (' . sql_marks(count($ids)) . ')', $ids);
    foreach ($rows as $r) $custom[(int)$r['post_id']] = (string)$r['image'];
    $cls = 'cover-thumb' . ($cfg['position'] === 'right' ? ' cover-thumb-right' : ($cfg['position'] === 'alternate' ? ' cover-thumb-alt' : ''));
    $width = (int)$cfg['width'];
    $out = preg_replace_callback('/<article class="post-item"[^>]*data-post-id="(\d+)"[^>]*>/', static function (array $m) use ($pending, $custom, $cfg, $cls, $width): string {
        $pid = (int)$m[1];
        if (!array_key_exists($pid, $pending)) return $m[0];
        $img = (string)($custom[$pid] ?? '');
        if ($img === '') $img = (string)$pending[$pid];
        if ($img === '') $img = (string)$cfg['default'];
        if ($img === '') return $m[0];
        return $m[0] . '<div class="' . $cls . '" style="--cover-w:' . $width . 'px"><img src="' . h($img) . '" alt="" loading="lazy"></div>';
    }, $html);
    return $out ?? $html;
}

// page.before_render：写文章页注入表单字段，其余页面做列表回填。
function cover_render(string $html, array $ctx): string
{
    $a = (string)($_GET['a'] ?? '');
    if (($a === 'write' || $a === 'edit') && is_admin()) return cover_inject_editor_field($html);
    return cover_inject_list($html);
}

// post.after_save：按提交的封面图更新（留空删除；非法地址保持原值不动）。
function cover_after_save(mixed $value, array $ctx): void
{
    if (!array_key_exists('plugin_cover_url', $_POST)) return;
    $pid = (int)($ctx['post_id'] ?? 0);
    if ($pid <= 0) return;
    $raw = trim((string)$_POST['plugin_cover_url']);
    if ($raw === '') {
        q('DELETE FROM plugin_cover_covers WHERE post_id=?', [$pid]);
        return;
    }
    $url = cover_valid_image($raw);
    if ($url === '') return;
    app_db_upsert('plugin_cover_covers', ['post_id' => $pid, 'image' => $url, 'updated_at' => now()], ['post_id']);
}

// post.after_delete：随文章删除清理封面记录。
function cover_after_delete(mixed $value, array $ctx): void
{
    $pid = (int)($ctx['post_id'] ?? 0);
    if ($pid > 0) q('DELETE FROM plugin_cover_covers WHERE post_id=?', [$pid]);
}

function cover_admin(array $plugin): string
{
    if (is_post_request()) {
        $pos = (string)($_POST['position'] ?? '');
        plugin_save_config('cover', [
            'position' => in_array($pos, ['left', 'right', 'alternate'], true) ? $pos : 'left',
            'width' => min(400, max(60, (int)($_POST['width'] ?? 150))),
            'default' => cover_valid_image(post('default', 250)),
        ]);
        set_flash('封面设置已保存');
        go(admin_url(['tab' => 'plugins', 'view' => 'cover']));
    }
    $cfg = cover_config();
    return '<p style="color:var(--text-muted);font-size:var(--font-size-sm);margin-top:0">写文章页「正文」下方可填写单篇封面图；留空自动取正文第一张图片，都没有时使用下方默认封面图。</p>'
        . '<form method="post">' . form_token()
        . '<input type="hidden" name="admin_action" value="noop">'
        . select_input('显示位置', 'position', $cfg['position'], ['left' => '缩略图在左（默认）', 'right' => '缩略图在右', 'alternate' => '左右交替（逐条左/右轮换）'])
        . '<div class="form-grid-2">'
        . input('缩略图宽度', 'width', (string)$cfg['width'], 'number', false, '60 - 400 像素，高度按 3:2 等比裁切')
        . input('默认封面图', 'default', $cfg['default'], 'text', false, '兜底图片地址；留空则无图不出缩略图')
        . '</div>'
        . '<button class="btn" type="submit">保存设置</button></form>';
}

function cover_css(): string
{
    return <<<'CSS'
.post-item:has(> .cover-thumb){display:flow-root;min-height:104px}
.cover-thumb{float:left;width:var(--cover-w,150px);margin:2px 16px 10px 0;border:1px solid var(--border);border-radius:calc(var(--radius) - 2px);overflow:hidden;background:var(--muted)}
.cover-thumb img{display:block;width:100%;aspect-ratio:3/2;object-fit:cover}
.cover-thumb-right{float:right;margin:2px 0 10px 16px}
.post-item:nth-of-type(even) > .cover-thumb-alt{float:right;margin:2px 0 10px 16px}
@media (max-width:640px){.cover-thumb{width:96px}}
CSS;
}

function cover_install(array $plugin): void
{
    $t = app_db_types();
    app_db_create_table('plugin_cover_covers', "id {$t['id']},post_id {$t['uint']} NOT NULL,image {$t['string']} NOT NULL,updated_at {$t['uint']} NOT NULL,UNIQUE (post_id)");
}

function cover_uninstall(array $plugin, bool $keep_data = true): void
{
    if (!$keep_data) app_db_drop_table('plugin_cover_covers');
}

return [
    'id' => 'cover',
    'name' => '文章封面',
    'version' => '1.0.0',
    'description' => '为文章列表增加封面缩略图：自动取正文首图或逐篇自定义，支持左侧 / 右侧 / 左右交替显示。',
    'author' => 'Mono',
    'assets' => ['css' => 'cover_css'],
    'hooks' => [
        'post.list.after_render' => 'cover_collect',
        'page.before_render' => 'cover_render',
        'post.after_save' => 'cover_after_save',
        'post.after_delete' => 'cover_after_delete',
    ],
    'admin_tabs' => ['cover' => 'cover_admin'],
    'install' => 'cover_install',
    'uninstall' => 'cover_uninstall',
];
