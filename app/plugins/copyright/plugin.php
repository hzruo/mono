<?php
if (!defined('APP_ROOT')) exit;

/**
 * 版权声明插件（copyright）。
 *
 * 文章详情页正文末尾（markdown.after，仅 ?a=post 时生效）输出「原创声明」卡片。
 * 默认 CC BY-SA 4.0，可在后台切换其它 CC 协议 / 保留所有权利 / 自定义协议；
 * 作者默认取站点名称，可覆盖；另可附加一行说明。零建表、零路由、零前端脚本。
 */

function copyright_licenses(): array
{
    return [
        'by-sa' => ['name' => 'CC BY-SA 4.0', 'url' => 'https://creativecommons.org/licenses/by-sa/4.0/deed.zh-hans'],
        'by' => ['name' => 'CC BY 4.0', 'url' => 'https://creativecommons.org/licenses/by/4.0/deed.zh-hans'],
        'by-nc-sa' => ['name' => 'CC BY-NC-SA 4.0', 'url' => 'https://creativecommons.org/licenses/by-nc-sa/4.0/deed.zh-hans'],
        'by-nc' => ['name' => 'CC BY-NC 4.0', 'url' => 'https://creativecommons.org/licenses/by-nc/4.0/deed.zh-hans'],
        'by-nd' => ['name' => 'CC BY-ND 4.0', 'url' => 'https://creativecommons.org/licenses/by-nd/4.0/deed.zh-hans'],
        'by-nc-nd' => ['name' => 'CC BY-NC-ND 4.0', 'url' => 'https://creativecommons.org/licenses/by-nc-nd/4.0/deed.zh-hans'],
        'reserved' => ['name' => '保留所有权利', 'url' => ''],
        'custom' => ['name' => '自定义', 'url' => ''],
    ];
}

function copyright_config(): array
{
    $raw = plugin_config('copyright', []);
    $license = (string)($raw['license'] ?? 'by-sa');
    if (!isset(copyright_licenses()[$license])) $license = 'by-sa';
    $custom_url = trim((string)($raw['custom_url'] ?? ''));
    return [
        'license' => $license,
        'author' => trim((string)($raw['author'] ?? '')),
        'custom_name' => trim((string)($raw['custom_name'] ?? '')),
        'custom_url' => preg_match('#^https?://#i', $custom_url) === 1 ? $custom_url : '',
        'extra' => trim((string)($raw['extra'] ?? '')),
    ];
}

// 正文末尾注入声明卡片；独立页面 / 预览 / RSS 不注入（仅文章详情页生效）。
function copyright_notice(string $html, array $ctx): string
{
    if ((string)($_GET['a'] ?? '') !== 'post') return $html;
    $cfg = copyright_config();
    $author = $cfg['author'] !== '' ? $cfg['author'] : trim(setting('site_name', 'Mono'));
    if ($author === '') $author = 'Mono';
    $pid = (int)($_GET['id'] ?? 0);
    $source = $pid > 0 ? absolute_url(route_url('post', ['id' => $pid])) : '';
    $credit = $source !== ''
        ? '，转载请注明原作者与<a href="' . h($source) . '">原文链接</a>'
        : '，转载请注明原作者';

    if ($cfg['license'] === 'reserved') {
        $line = '本文为 <strong>' . h($author) . '</strong> 原创内容，保留所有权利。未经作者授权，禁止任何形式的转载。';
    } elseif ($cfg['license'] === 'custom') {
        $name = $cfg['custom_name'] !== '' ? $cfg['custom_name'] : '自定义许可协议';
        $lic = $cfg['custom_url'] !== ''
            ? '<a href="' . h($cfg['custom_url']) . '" target="_blank" rel="noopener nofollow">' . h($name) . '</a>'
            : h($name);
        $line = '本文为 <strong>' . h($author) . '</strong> 原创内容，依据 ' . $lic . ' 授权使用' . $credit . '。';
    } else {
        $lic = copyright_licenses()[$cfg['license']];
        $line = '本文为 <strong>' . h($author) . '</strong> 原创内容，采用 '
            . '<a href="' . h($lic['url']) . '" target="_blank" rel="noopener nofollow">' . h($lic['name']) . '</a>'
            . ' 许可协议' . $credit . '。';
    }

    $note = '<div class="copyright-notice"><span class="copyright-tag">原创声明</span><p class="copyright-line">' . $line . '</p>';
    if ($cfg['extra'] !== '') $note .= '<p class="copyright-line copyright-extra">' . h($cfg['extra']) . '</p>';
    return $html . $note . '</div>';
}

function copyright_admin(array $plugin): string
{
    if (is_post_request()) {
        $license = (string)($_POST['license'] ?? 'by-sa');
        if (!isset(copyright_licenses()[$license])) $license = 'by-sa';
        $custom_url = post('custom_url', 250);
        plugin_save_config('copyright', [
            'license' => $license,
            'author' => post('author', 60),
            'custom_name' => post('custom_name', 60),
            'custom_url' => preg_match('#^https?://#i', $custom_url) === 1 ? $custom_url : '',
            'extra' => post('extra', 120),
        ]);
        set_flash('版权声明设置已保存');
        go(admin_url(['tab' => 'plugins', 'view' => 'copyright']));
    }
    $cfg = copyright_config();
    $options = [];
    foreach (copyright_licenses() as $key => $lic) $options[$key] = $lic['name'];
    $options['custom'] = '自定义协议（使用下方名称与地址）';
    return '<form method="post">' . form_token()
        . '<input type="hidden" name="admin_action" value="noop">'
        . select_input('许可协议', 'license', $cfg['license'], $options, '默认 CC BY-SA 4.0（署名-相同方式共享）')
        . input('作者名', 'author', $cfg['author'], 'text', false, '留空使用站点名称')
        . '<div class="form-grid-2">'
        . input('自定义协议名称', 'custom_name', $cfg['custom_name'], 'text', false, '仅「自定义协议」时生效')
        . input('自定义协议地址', 'custom_url', $cfg['custom_url'], 'url', false, '仅「自定义协议」时生效')
        . '</div>'
        . input('附加说明', 'extra', $cfg['extra'], 'text', false, '可选，显示在声明下方的一行文字')
        . '<button class="btn" type="submit">保存设置</button></form>';
}

function copyright_css(): string
{
    return <<<'CSS'
.copyright-notice{margin-top:26px;padding:14px 16px;border:1px solid var(--border);border-radius:calc(var(--radius) - 2px);background:var(--muted);font-size:var(--font-size-sm);color:var(--muted-foreground);line-height:1.7}
.copyright-notice .copyright-tag{display:inline-block;padding:1px 8px;margin-bottom:6px;border-radius:999px;background:var(--secondary);color:var(--secondary-foreground);font-size:var(--font-size-xs);font-weight:600}
.copyright-notice .copyright-line{margin:0}
.copyright-notice .copyright-line + .copyright-line{margin-top:4px}
.copyright-notice a{color:var(--muted-foreground);text-decoration:underline;text-underline-offset:2px}
.copyright-notice a:hover{color:var(--foreground)}
.copyright-notice strong{color:var(--foreground)}
CSS;
}

return [
    'id' => 'copyright',
    'name' => '版权声明',
    'version' => '1.0.0',
    'description' => '在文章正文末尾展示原创声明卡片，默认 CC BY-SA 4.0 许可协议，支持切换其它协议或自定义。',
    'author' => 'Mono',
    'assets' => ['css' => 'copyright_css'],
    'hooks' => ['markdown.after' => 'copyright_notice'],
    'admin_tabs' => ['copyright' => 'copyright_admin'],
];
