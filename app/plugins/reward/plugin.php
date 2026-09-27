<?php
if (!defined('APP_ROOT')) exit;

/**
 * 赞赏插件（reward）。
 *
 * 文章详情页正文末尾（markdown.after，仅 ?a=post 时生效）输出「赞赏」按钮（无整块背景面板，直接置于正文之上）：
 * 点击弹出赞赏弹窗展示微信 / 支付宝等赞赏码，多个赞赏码在弹窗内以 tab 切换。
 *
 * 赞赏码来源两种（后台上传优先）：
 * - 上传图片：存 DATA_DIR/plugins/reward/reward-<hash>.<ext>，经 reward_img 路由输出（不暴露真实路径）；
 * - 图片地址：http(s) 或站内路径直接引用。
 * 配置存插件 config JSON（零建表）；前台交互由 assets js（合并进 plugins.js）承担。
 */

function reward_dir(): string { return DATA_DIR . '/plugins/reward'; }

// 上传文件名白名单（防路径穿越），格式与上传时生成的一致。
function reward_valid_file(string $name): bool
{
    return preg_match('/^reward-[a-f0-9]{12}\.(png|jpg|jpeg|gif|webp)$/', $name) === 1;
}

function reward_mime(string $name): string
{
    $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    return ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'][$ext] ?? '';
}

// 赞赏码地址校验：'file:xxx'（本插件上传的文件）或 http(s)/站内路径，禁止空白/引号/尖括号。
function reward_valid_image(string $url): string
{
    $url = trim($url);
    if ($url === '' || mb_strlen($url) > 300) return '';
    if (str_starts_with($url, 'file:')) {
        $name = substr($url, 5);
        return reward_valid_file($name) && is_file(reward_dir() . '/' . $name) ? 'file:' . $name : '';
    }
    if (preg_match('#^(https?://|/)#i', $url) !== 1) return '';
    return preg_match('/[\s"\'<>]/', $url) === 1 ? '' : $url;
}

// 配置存取：title / note / codes[{type,label,image}]，codes 最多 10 个。
function reward_config(): array
{
    $raw = plugin_config('reward', []);
    $title = trim((string)($raw['title'] ?? ''));
    $codes = [];
    foreach ((array)($raw['codes'] ?? []) as $it) {
        if (!is_array($it)) continue;
        $image = reward_valid_image((string)($it['image'] ?? ''));
        if ($image === '') continue;
        $type = (string)($it['type'] ?? 'other');
        if (!in_array($type, ['wechat', 'alipay', 'other'], true)) $type = 'other';
        $codes[] = ['type' => $type, 'label' => trim((string)($it['label'] ?? '')), 'image' => $image];
        if (count($codes) >= 10) break;
    }
    return [
        'title' => $title !== '' ? $title : '赞赏支持',
        'note' => trim((string)($raw['note'] ?? '')),
        'codes' => $codes,
    ];
}

function reward_type_name(string $type): string
{
    return ['wechat' => '微信', 'alipay' => '支付宝', 'other' => '其它'][$type] ?? '其它';
}

// file:xxx 转输出路由 URL；其它地址原样返回。
function reward_image_url(string $image): string
{
    if (str_starts_with($image, 'file:')) return route_url('reward_img', ['f' => substr($image, 5)]);
    return $image;
}

// 处理上传的赞赏码：成功返回 'file:xxx'；未上传返回 '' 且 $error 为空；失败返回 '' 并带出原因。
function reward_store_upload(string &$error): string
{
    $f = $_FILES['image_file'] ?? null;
    if (!is_array($f) || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return '';
    if ((int)$f['error'] !== UPLOAD_ERR_OK) { $error = '上传失败（错误码 ' . (int)$f['error'] . '）'; return ''; }
    if ((int)($f['size'] ?? 0) > 2 * 1024 * 1024) { $error = '赞赏码图片不能超过 2MB'; return ''; }
    if (!is_uploaded_file((string)$f['tmp_name'])) { $error = '非法的上传文件'; return ''; }
    $info = @getimagesize((string)$f['tmp_name']);
    $ext = $info === false ? false : array_search((string)$info['mime'], ['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'], true);
    if ($ext === false) { $error = '仅支持 PNG/JPG/GIF/WebP 格式'; return ''; }
    if (!is_dir(reward_dir())) mkdir(reward_dir(), 0755, true);
    $name = 'reward-' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file((string)$f['tmp_name'], reward_dir() . '/' . $name)) { $error = '保存失败，请检查目录写权限'; return ''; }
    return 'file:' . $name;
}

// 删除本插件上传的赞赏码文件（file: 前缀才处理）。
function reward_drop_file(string $image): void
{
    if (!str_starts_with($image, 'file:')) return;
    $name = substr($image, 5);
    if (!reward_valid_file($name)) return;
    $file = reward_dir() . '/' . $name;
    if (is_file($file)) @unlink($file);
}

function reward_heart_svg(): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>';
}

// 正文末尾注入「赞赏」按钮 + 隐藏弹窗；独立页面 / 预览 / RSS 不注入（仅文章详情页生效）。
function reward_render(string $html, array $ctx): string
{
    if ((string)($_GET['a'] ?? '') !== 'post') return $html;
    $cfg = reward_config();
    if (!$cfg['codes']) return $html;

    // 多个赞赏码时弹窗内以 tab 切换；单个时直接展示（不渲染 tab 条）。
    $count = count($cfg['codes']);
    $tabs = '';
    $pages = '';
    foreach ($cfg['codes'] as $i => $code) {
        $label = $code['label'] !== '' ? $code['label'] : reward_type_name($code['type']);
        $active = $i === 0 ? ' is-active' : '';
        if ($count > 1) $tabs .= '<button type="button" class="reward-tab' . $active . '" data-reward-tab="' . $i . '">' . h($label) . '</button>';
        $pages .= '<div class="reward-page' . $active . '" data-reward-page="' . $i . '">'
            . '<img src="' . h(reward_image_url($code['image'])) . '" alt="' . h($label . '赞赏码') . '" loading="lazy">'
            . ($count > 1 ? '' : '<span class="reward-code-label">' . h($label) . '</span>')
            . '</div>';
    }
    $note = $cfg['note'] !== '' ? '<p class="reward-note">' . h($cfg['note']) . '</p>' : '';

    return $html
        . '<div class="reward-box" data-reward>'
        . '<button type="button" class="reward-open" data-reward-open>' . reward_heart_svg() . h($cfg['title']) . '</button>'
        . '<div class="reward-mask" hidden><div class="reward-dialog" role="dialog" aria-modal="true" aria-label="' . h($cfg['title']) . '">'
        . '<button type="button" class="reward-close" data-reward-close aria-label="关闭">'
        . '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>'
        . '</button>'
        . $note
        . ($count > 1 ? '<div class="reward-tabs">' . $tabs . '</div>' : '')
        . '<div class="reward-body">' . $pages . '</div>'
        . '</div></div></div>';
}

// 赞赏码输出路由：?a=reward_img&f=reward-<hash>.<ext>（伪静态 /reward_img?f=...）。
function reward_img_route(array $plugin): void
{
    $name = (string)($_GET['f'] ?? '');
    $mime = reward_valid_file($name) ? reward_mime($name) : '';
    $file = $mime !== '' ? reward_dir() . '/' . $name : '';
    if ($file === '' || !is_file($file)) err('赞赏码不存在', 404);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($file));
    header('Cache-Control: public, max-age=86400');
    readfile($file);
    exit;
}

// 后台管理页：POST 自处理（设置 / 添加 / 删除），渲染赞赏码列表 + 表单。
function reward_admin(array $plugin): string
{
    $back = admin_url(['tab' => 'plugins', 'view' => 'reward']);
    if (is_post_request()) {
        $faction = (string)($_POST['faction'] ?? '');
        if ($faction === 'settings') {
            $cfg = reward_config();
            plugin_save_config('reward', [
                'title' => post('title', 40) ?: '赞赏支持',
                'note' => post('note', 120),
                'codes' => $cfg['codes'],
            ]);
            set_flash('赞赏设置已保存');
            go($back);
        }
        if ($faction === 'add') {
            $cfg = reward_config();
            if (count($cfg['codes']) >= 10) {
                set_flash('最多添加 10 个赞赏码', 'error');
                go($back);
            }
            $error = '';
            $image = reward_store_upload($error);
            if ($image === '' && $error === '') $image = reward_valid_image(post('image_url', 300));
            if ($image === '') {
                set_flash($error !== '' ? $error : '请上传赞赏码图片或填写有效的图片地址', 'error');
                go($back);
            }
            $type = (string)($_POST['type'] ?? 'wechat');
            if (!in_array($type, ['wechat', 'alipay', 'other'], true)) $type = 'other';
            $cfg['codes'][] = ['type' => $type, 'label' => post('label', 30), 'image' => $image];
            plugin_save_config('reward', ['title' => $cfg['title'], 'note' => $cfg['note'], 'codes' => $cfg['codes']]);
            set_flash('赞赏码已添加');
            go($back);
        }
        if ($faction === 'delete') {
            $cfg = reward_config();
            $idx = (int)($_POST['idx'] ?? -1);
            if (isset($cfg['codes'][$idx])) {
                reward_drop_file($cfg['codes'][$idx]['image']);
                unset($cfg['codes'][$idx]);
                $cfg['codes'] = array_values($cfg['codes']);
                plugin_save_config('reward', ['title' => $cfg['title'], 'note' => $cfg['note'], 'codes' => $cfg['codes']]);
                set_flash('赞赏码已删除');
            }
            go($back);
        }
    }

    $cfg = reward_config();

    // 1) 已配置的赞赏码列表。
    $html = '<div class="card-title" style="font-size:var(--font-size-md)">赞赏码（' . count($cfg['codes']) . '/10）</div>';
    if ($cfg['codes']) {
        $html .= '<div class="reward-admin-grid">';
        foreach ($cfg['codes'] as $i => $code) {
            $label = $code['label'] !== '' ? $code['label'] : reward_type_name($code['type']);
            $html .= '<div class="reward-admin-item">'
                . '<img src="' . h(reward_image_url($code['image'])) . '" alt="">'
                . '<div class="reward-admin-meta"><strong>' . h($label) . '</strong><span>' . h(reward_type_name($code['type'])) . '</span></div>'
                . post_action_form($back, '删除', ['admin_action' => 'noop', 'faction' => 'delete', 'idx' => $i], 'btn sm danger', '删除赞赏码「' . $label . '」？')
                . '</div>';
        }
        $html .= '</div>';
    } else {
        $html .= '<p style="color:var(--text-muted);font-size:var(--font-size-sm);margin:0">还没有赞赏码，使用下方表单添加第一个。</p>';
    }

    // 2) 添加表单（上传与地址二选一，上传优先）。
    $html .= '<div class="card-title" style="font-size:var(--font-size-md);margin-top:22px">添加赞赏码</div>'
        . '<form method="post" enctype="multipart/form-data">' . form_token()
        . '<input type="hidden" name="admin_action" value="noop">'
        . '<input type="hidden" name="faction" value="add">'
        . '<div class="form-grid-2">'
        . select_input('类型', 'type', 'wechat', ['wechat' => '微信', 'alipay' => '支付宝', 'other' => '其它'])
        . input('说明文字', 'label', '', 'text', false, '显示在赞赏码下方，留空使用类型名')
        . '</div>'
        . input('图片地址', 'image_url', '', 'text', false, 'http(s) 或站内路径；与下方上传二选一，上传优先')
        . '<label class="grid"><span class="field-caption">上传图片<span class="field-help">PNG/JPG/GIF/WebP，不超过 2MB</span></span><input type="file" name="image_file" accept="image/*"></label>'
        . '<button class="btn" type="submit">添加赞赏码</button></form>';

    // 3) 展示设置。
    $html .= '<div class="card-title" style="font-size:var(--font-size-md);margin-top:22px">展示设置</div>'
        . '<form method="post">' . form_token()
        . '<input type="hidden" name="admin_action" value="noop">'
        . '<input type="hidden" name="faction" value="settings">'
        . input('按钮文字', 'title', $cfg['title'], 'text', false, '文章末尾展开按钮上的文字')
        . input('提示说明', 'note', $cfg['note'], 'text', false, '展开后显示在赞赏码上方的一行文字，可留空')
        . '<button class="btn" type="submit">保存设置</button></form>';

    return $html;
}

// --- 前端脚本（assets js，合并进 plugins.js）---
// 按钮打开弹窗 → tab 切换 → 遮罩 / 关闭键 / Esc 关闭；纯事件委托，不依赖 DOM 就绪时机。
function reward_js(): string
{
    return <<<'JS'
(function () {
  'use strict';
  function closest(el, sel) { return el && el.closest ? el.closest(sel) : null; }
  function closeMask(mask) { if (mask) mask.hidden = true; }

  document.addEventListener('click', function (e) {
    var open = closest(e.target, '[data-reward-open]');
    if (open) {
      var box = closest(open, '[data-reward]');
      var mask = box ? box.querySelector('.reward-mask') : null;
      if (mask) {
        mask.hidden = false;
        var btn = mask.querySelector('[data-reward-close]');
        if (btn) btn.focus();
      }
      return;
    }
    var close = closest(e.target, '[data-reward-close]');
    if (close) { closeMask(closest(close, '.reward-mask')); return; }
    var tab = closest(e.target, '[data-reward-tab]');
    if (tab) {
      var m = closest(tab, '.reward-mask');
      var idx = tab.getAttribute('data-reward-tab');
      if (m) {
        var tabs = m.querySelectorAll('[data-reward-tab]');
        for (var i = 0; i < tabs.length; i++) tabs[i].classList.toggle('is-active', tabs[i].getAttribute('data-reward-tab') === idx);
        var pages = m.querySelectorAll('[data-reward-page]');
        for (var j = 0; j < pages.length; j++) pages[j].classList.toggle('is-active', pages[j].getAttribute('data-reward-page') === idx);
      }
      return;
    }
    // 点击遮罩空白处（非弹窗内容）关闭。
    if (e.target && e.target.classList && e.target.classList.contains('reward-mask')) closeMask(e.target);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var masks = document.querySelectorAll('.reward-mask');
    for (var i = 0; i < masks.length; i++) if (!masks[i].hidden) { e.preventDefault(); closeMask(masks[i]); }
  });
}());
JS;
}

function reward_css(): string
{
    return <<<'CSS'
/* 文末按钮：实心胶囊直接置于正文之上（不再有整块背景面板），hover 轻微降透明 */
.reward-box{display:flex;justify-content:center;margin-top:26px}
.reward-box .reward-open{display:inline-flex;align-items:center;gap:7px;padding:7px 22px;border:0;border-radius:999px;background:var(--primary);color:var(--primary-foreground);font-family:inherit;font-size:var(--font-size-sm);font-weight:600;cursor:pointer;transition:opacity .15s}
.reward-box .reward-open:hover{opacity:.88}
/* 弹窗：遮罩 + 居中卡片，风格与站内确认弹窗一致（z-index 同层） */
.reward-mask{position:fixed;inset:0;z-index:130;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.45);animation:reward-fade .16s ease}
.reward-dialog{position:relative;width:100%;max-width:340px;padding:22px 20px;background:var(--card);color:var(--card-foreground);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-md);animation:reward-in .18s ease}
.reward-dialog .reward-close{position:absolute;top:8px;right:8px;display:grid;place-items:center;width:30px;height:30px;padding:0;border:0;border-radius:calc(var(--radius) - 6px);background:transparent;color:var(--muted-foreground);cursor:pointer;transition:background-color .15s,color .15s}
.reward-dialog .reward-close:hover{background:var(--accent);color:var(--foreground)}
.reward-dialog .reward-note{margin:0 0 12px;text-align:center;font-size:var(--font-size-sm);color:var(--muted-foreground);line-height:1.6}
.reward-dialog .reward-tabs{display:flex;flex-wrap:wrap;justify-content:center;gap:6px;margin-bottom:14px}
.reward-dialog .reward-tab{padding:5px 15px;border:1px solid var(--border);border-radius:999px;background:transparent;color:var(--muted-foreground);font-family:inherit;font-size:var(--font-size-xs);cursor:pointer;transition:color .15s,border-color .15s,background-color .15s}
.reward-dialog .reward-tab:hover{color:var(--foreground);border-color:var(--ring)}
.reward-dialog .reward-tab.is-active{background:var(--primary);border-color:var(--primary);color:var(--primary-foreground)}
.reward-dialog .reward-page{display:none;justify-items:center;gap:8px}
.reward-dialog .reward-page.is-active{display:grid}
.reward-dialog .reward-page img{display:block;width:200px;max-width:100%;height:auto;padding:6px;border:1px solid var(--border);border-radius:calc(var(--radius) - 4px);background:#fff}
.reward-dialog .reward-code-label{font-size:var(--font-size-xs);color:var(--muted-foreground)}
@keyframes reward-fade{from{opacity:0}to{opacity:1}}
@keyframes reward-in{from{opacity:0;transform:translateY(6px) scale(.97)}to{opacity:1;transform:none}}
.reward-admin-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px}
.reward-admin-item{display:flex;align-items:center;gap:10px;padding:10px;border:1px solid var(--border);border-radius:calc(var(--radius) - 4px);background:var(--card)}
.reward-admin-item img{flex:none;width:44px;height:44px;object-fit:cover;border:1px solid var(--border);border-radius:6px;background:#fff}
.reward-admin-meta{min-width:0;flex:1;display:grid;gap:2px}
.reward-admin-meta strong{font-size:var(--font-size-sm)}
.reward-admin-meta span{font-size:var(--font-size-xs);color:var(--muted-foreground)}
CSS;
}

function reward_uninstall(array $plugin, bool $keep_data = true): void
{
    if ($keep_data) return;
    $cfg = reward_config();
    foreach ($cfg['codes'] as $code) reward_drop_file($code['image']);
    // 兜底清理目录中遗留的上传文件。
    foreach (glob(reward_dir() . '/reward-*') ?: [] as $file) {
        if (is_file($file) && reward_valid_file(basename($file))) @unlink($file);
    }
}

return [
    'id' => 'reward',
    'name' => '赞赏',
    'version' => '1.0.0',
    'description' => '文章正文末尾展示融入版面的「赞赏」按钮，点击弹出赞赏码弹窗（多个以 tab 切换），支持微信 / 支付宝与后台上传。',
    'author' => 'Mono',
    'assets' => ['css' => 'reward_css', 'js' => 'reward_js'],
    'hooks' => ['markdown.after' => 'reward_render'],
    'routes' => ['reward_img' => 'reward_img_route'],
    'admin_tabs' => ['reward' => 'reward_admin'],
    'uninstall' => 'reward_uninstall',
];
