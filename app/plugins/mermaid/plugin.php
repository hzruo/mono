<?php
if (!defined('APP_ROOT')) exit;

/**
 * Mermaid 图表插件（mermaid）。
 *
 * 在文章中使用 ```mermaid 代码块即可绘制流程图、时序图、甘特图等。
 * 渲染遵循官方 getting-started 的浏览器用法：ESM 模块从 CDN 导入并
 * `mermaid.initialize({ startOnLoad: true })`，图表源码放在 <pre class="mermaid"> 中。
 * - hook markdown.after：把 mermaid 代码块外壳替换为官方 <pre class="mermaid">。
 * - hook page.head：仅当本页含图表时注入官方 ESM 渲染脚本，暗色下自动切 dark 主题。
 * - admin_tabs mermaid：配置 mermaid 主版本与是否随站点暗色切换主题。
 */

function mermaid_config(): array
{
    $raw = plugin_config('mermaid', []);
    $ver = (string)($raw['version'] ?? '12');
    if (!preg_match('/^\d+(?:\.\d+){0,2}$/', $ver)) $ver = '12';
    return [
        'version' => $ver,
        'theme_auto' => (int)($raw['theme_auto'] ?? 1) === 1,
    ];
}

// 将 mermaid 代码块外壳替换为官方 <pre class="mermaid">，并标记本页需要渲染器。
function mermaid_markdown(string $value, array $ctx): string
{
    $out = preg_replace_callback(
        '/<div class="codeblock" data-lang="mermaid">.*?<pre><code[^>]*>([\s\S]*?)<\/code><\/pre><\/div>/s',
        function ($m) {
            $GLOBALS['__mermaid_present'] = true;
            return '<pre class="mermaid">' . $m[1] . '</pre>';
        },
        $value
    );
    return $out ?? $value;
}

// 仅在本页含图表时注入官方 ESM 脚本（避免无谓的 CDN 请求）。
function mermaid_head(string $value, array $ctx): string
{
    if (empty($GLOBALS['__mermaid_present'])) return $value;
    $cfg = mermaid_config();
    $theme = $cfg['theme_auto']
        ? '(document.documentElement.classList.contains(\'dark\') ? \'dark\' : \'default\')'
        : '\'default\'';
    return $value
        . '<script type="module">import mermaid from "https://cdn.jsdelivr.net/npm/mermaid@' . h($cfg['version']) . '/dist/mermaid.esm.min.mjs";'
        . 'mermaid.initialize({ startOnLoad: true, theme: ' . $theme . ' });</script>';
}

// 图表容器样式：覆盖核心 pre 的深色代码块外观，居中自适应。
function mermaid_css(): string
{
    return <<<'CSS'
.content pre.mermaid{background:transparent;border:0;padding:12px 0;margin:18px 0;display:flex;justify-content:center;overflow-x:auto}
.content pre.mermaid svg{max-width:100%;height:auto}
CSS;
}

// 后台配置页。
function mermaid_admin(array $plugin): string
{
    if (is_post_request()) {
        plugin_save_config('mermaid', [
            'version' => (string)($_POST['version'] ?? '12'),
            'theme_auto' => (int)($_POST['theme_auto'] ?? 0) === 1 ? 1 : 0,
        ]);
        set_flash('Mermaid 设置已保存');
        go(admin_url(['tab' => 'plugins', 'view' => 'mermaid']));
    }
    $cfg = mermaid_config();
    return '<form method="post">' . form_token()
        . '<input type="hidden" name="admin_action" value="noop">'
        . input('mermaid 主版本', 'version', $cfg['version'], 'text', false, 'CDN 加载的主版本号，如 12')
        . checkbox('暗色下自动使用 dark 主题', 'theme_auto', $cfg['theme_auto'])
        . '<button class="btn" type="submit">保存设置</button></form>';
}

return [
    'id' => 'mermaid',
    'name' => 'Mermaid 图表',
    'version' => '1.0.0',
    'description' => '在文章中用 ```mermaid 代码块绘制流程图、时序图、甘特图等，官方最新 ESM 渲染。',
    'author' => 'Mono',
    'assets' => ['css' => 'mermaid_css'],
    'hooks' => ['markdown.after' => 'mermaid_markdown', 'page.head' => 'mermaid_head'],
    'admin_tabs' => ['mermaid' => 'mermaid_admin'],
];
