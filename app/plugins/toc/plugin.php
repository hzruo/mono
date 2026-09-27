<?php
if (!defined('APP_ROOT')) exit;

/**
 * 文章目录插件（toc）。
 *
 * 「万物皆插件」：核心不含目录逻辑，目录能力由本插件通过两个 Hook 提供，零核心改动。
 * - hook markdown.after：给正文标题注入锚点 id、收集标题层级；标题数达标时在正文顶部插入
 *   移动端专用的折叠目录（>900px 由 CSS 隐藏）。执行时机早于 sidebar.stack。
 * - hook sidebar.stack：把「目录」卡片插到右侧栏首位。侧栏本身是 position:sticky，
 *   因此首卡在桌面端滚动时常驻可见；≤900px 侧栏下移，故该卡隐藏、改用正文内联目录。
 * - hook 数据传递：同一次请求内用 $GLOBALS['__toc_items'] 暂存收集结果（无状态、不落库）。
 * - admin_tabs toc：配置参与层级、最少标题数、是否显示移动端内联目录。
 * 无数据表，启停/卸载不产生残留。
 */

// 可选层级组合白名单（后台下拉与此一致）。
function toc_level_options(): array
{
    return [
        '1,2,3' => 'H1 + H2 + H3（推荐：正文用 # 分节时）',
        '2,3' => 'H2 + H3（正文用 ## 开头时）',
        '1,2,3,4' => 'H1 ~ H4',
        '2,3,4' => 'H2 ~ H4',
        '2' => '仅 H2',
    ];
}

// 归一化配置（旧配置缺字段不报错）。
function toc_config(): array
{
    $raw = plugin_config('toc', []);
    $levels = (string)($raw['levels'] ?? '1,2,3');
    if (!isset(toc_level_options()[$levels])) $levels = '1,2,3';
    return [
        'levels' => array_map('intval', explode(',', $levels)),
        'min' => min(20, max(1, (int)($raw['min'] ?? 2))),
        'mobile' => (int)($raw['mobile'] ?? 1) === 1,
    ];
}

// 同请求内的标题收集结果（供 sidebar.stack 读取）。
function toc_store(?array $items = null): array
{
    if ($items !== null) $GLOBALS['__toc_items'] = $items;
    return $GLOBALS['__toc_items'] ?? [];
}

// 生成唯一锚点 id：英文标题走 slug，中文等 slug 为空时退化为序号，重复时追加 -2/-3。
function toc_anchor_id(string $text, array &$used, int $seq): string
{
    $slug = slugify($text);
    $base = $slug !== '' ? 'h-' . cut($slug, 60) : 'h-sec-' . $seq;
    $id = $base;
    $n = 2;
    while (isset($used[$id])) { $id = $base . '-' . $n; $n++; }
    $used[$id] = true;
    return $id;
}

/**
 * 目录列表 HTML：按标题层级递归嵌套（支持 H1~H4 任意深度）。
 * 算法：用一个层级栈跟踪已打开的 <ul>——更深则在上一个未闭合的 <li> 内开子列表，
 * 同级则先关闭上一个 <li>，更浅则逐层回退；末尾统一收尾。
 * 边界：首项不是最浅层级时（如先 ## 后 #），把比根层级更浅的项钳制为根级同项，
 * 避免出现第二个并列的根列表。
 */
function toc_list_html(array $items): string
{
    if (!$items) return '';
    $html = '';
    $stack = [];
    foreach ($items as $it) {
        $lv = (int)$it['level'];
        if ($stack && $lv < $stack[0]) $lv = $stack[0];
        while ($stack && end($stack) > $lv) { $html .= '</li></ul>'; array_pop($stack); }
        if (!$stack) {
            $html .= '<ul class="toc-list">';
            $stack[] = $lv;
        } elseif (end($stack) === $lv) {
            $html .= '</li>';
        } else {
            $html .= '<ul class="toc-sub">';
            $stack[] = $lv;
        }
        $html .= '<li><a class="toc-link" href="#' . h((string)$it['id']) . '" data-toc-id="' . h((string)$it['id']) . '">'
            . h((string)$it['text']) . '</a>';
    }
    while ($stack) { $html .= '</li></ul>'; array_pop($stack); }
    return $html;
}

/**
 * markdown.after：注入锚点 + 收集标题 +（可选）插入移动端内联目录。
 * 写文章页的分栏实时预览（?a=md_render）不处理，保持预览区干净。
 */
function toc_process_content(string $value, array $ctx): string
{
    if ($value === '' || (string)($_GET['a'] ?? '') === 'md_render') return $value;
    $cfg = toc_config();
    $levels = $cfg['levels'];
    $items = [];
    $used = [];

    // 仅处理核心 markdown_html 产出的裸标题（无属性）；已带 id/属性的标题不重复注入。
    $out = preg_replace_callback('/<h([1-6])>(.*?)<\/h\1>/s', function (array $m) use (&$items, &$used, $levels): string {
        $level = (int)$m[1];
        if (!in_array($level, $levels, true)) return $m[0];
        // 正文已被 h() 转义，需先解码再作为纯文本使用，避免目录里出现二次转义。
        $text = trim(html_entity_decode(strip_tags((string)$m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($text === '') return $m[0];
        $id = toc_anchor_id($text, $used, count($items) + 1);
        $items[] = ['level' => $level, 'id' => $id, 'text' => $text];
        return '<h' . $level . ' id="' . h($id) . '">' . $m[2] . '</h' . $level . '>';
    }, $value) ?? $value;

    if (count($items) < $cfg['min']) return $out;   // 标题太少不值得占位（锚点仍保留，可直接深链）
    toc_store($items);
    if (!$cfg['mobile']) return $out;
    return '<details class="toc-inline"><summary>目录（' . count($items) . '）</summary>'
        . '<nav class="toc-nav" aria-label="文章目录">' . toc_list_html($items) . '</nav></details>' . $out;
}

// sidebar.stack：目录卡插到右侧栏首位（侧栏 sticky，首卡常驻可见）。
function toc_sidebar(string $value, array $ctx): string
{
    $items = toc_store();
    if (!$items) return $value;
    return '<div class="card toc-card"><div class="card-title">目录</div>'
        . '<nav class="toc-nav" aria-label="文章目录">' . toc_list_html($items) . '</nav></div>' . $value;
}

function toc_css(): string
{
    return <<<'CSS'
/* 锚点跳转时避开固定顶栏（sticky header 约 64px + 余量） */
.content h1,.content h2,.content h3,.content h4{scroll-margin-top:88px}
@media (prefers-reduced-motion:no-preference){html{scroll-behavior:smooth}}
/* 侧栏目录卡：内部滚动，避免长目录把 sticky 侧栏顶出视口 */
.toc-card .toc-nav{max-height:calc(100vh - 190px);overflow:auto;margin:0 -4px;padding:0 4px}
.toc-list{list-style:none;margin:0;padding:0;font-size:var(--font-size-sm)}
.toc-list li{margin:0}
.toc-sub{list-style:none;margin:2px 0 6px;padding:0 0 0 12px;border-left:1px solid var(--line-soft)}
.toc-link{display:block;padding:4px 8px;border-radius:5px;color:var(--text-muted);text-decoration:none;line-height:1.5;border-left:2px solid transparent;transition:color .15s,background .15s}
.toc-link:hover{color:var(--primary);background:var(--accent)}
.toc-link.active{color:var(--primary);background:var(--brand-soft);border-left-color:var(--primary);font-weight:600}
.toc-sub .toc-link{font-size:var(--font-size-xs);padding:3px 8px}
.toc-sub .toc-sub{margin:2px 0 4px;padding-left:10px}
/* 移动端内联折叠目录：桌面端隐藏，改由侧栏卡承担 */
.toc-inline{margin:0 0 18px;border:1px solid var(--border);border-radius:calc(var(--radius) - 4px);background:var(--muted);overflow:hidden}
.toc-inline summary{cursor:pointer;padding:10px 14px;font-size:var(--font-size-sm);font-weight:600;color:var(--text);list-style:none;display:flex;align-items:center;gap:6px}
.toc-inline summary::-webkit-details-marker{display:none}
.toc-inline summary::before{content:'';width:0;height:0;border-left:5px solid var(--text-subtle);border-top:4px solid transparent;border-bottom:4px solid transparent;transition:transform .15s}
.toc-inline[open] summary::before{transform:rotate(90deg)}
.toc-inline .toc-nav{padding:0 10px 10px;max-height:52vh;overflow:auto}
.toc-inline .toc-link{background:transparent}
@media (min-width:901px){.toc-inline{display:none}}
@media (max-width:900px){.toc-card{display:none}}
CSS;
}

// 滚动高亮（scroll-spy）：取视口顶部附近最后一个已滚过的标题作为当前项。
function toc_js(): string
{
    return <<<'JS'
(function () {
  'use strict';
  function init() {
    var links = document.querySelectorAll('.toc-link');
    if (!links.length) return;
    var map = {}, heads = [];
    for (var i = 0; i < links.length; i++) {
      var id = links[i].getAttribute('data-toc-id');
      var el = id ? document.getElementById(id) : null;
      if (!el || map[id]) continue;
      map[id] = links[i];
      heads.push(el);
    }
    if (!heads.length) return;
    var active = null;
    function setActive(id) {
      if (active === id || !map[id]) return;
      if (active && map[active]) map[active].classList.remove('active');
      active = id;
      var link = map[id];
      link.classList.add('active');
      // 目录较长时让高亮项保持在目录可视区内。
      var nav = link.closest ? link.closest('.toc-nav') : null;
      if (nav) {
        var lr = link.getBoundingClientRect(), nr = nav.getBoundingClientRect();
        if (lr.top < nr.top || lr.bottom > nr.bottom) link.scrollIntoView({ block: 'nearest' });
      }
    }
    function onScroll() {
      // 与 CSS 的 scroll-margin-top 对齐：以视口顶部下 96px 处为判定线。
      var line = 96, cur = heads[0].id;
      for (var i = 0; i < heads.length; i++) {
        if (heads[i].getBoundingClientRect().top <= line) cur = heads[i].id; else break;
      }
      // 触底时高亮最后一项，避免尾部短章节永远选不中。
      if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4) cur = heads[heads.length - 1].id;
      setActive(cur);
    }
    var ticking = false;
    window.addEventListener('scroll', function () {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(function () { onScroll(); ticking = false; });
    }, { passive: true });
    window.addEventListener('resize', onScroll);
    onScroll();
    // 移动端内联目录：点条目后自动收起，避免遮挡正文。
    document.addEventListener('click', function (e) {
      var a = e.target.closest ? e.target.closest('.toc-inline .toc-link') : null;
      if (!a) return;
      var d = a.closest('details');
      if (d) d.removeAttribute('open');
    });
    // 带 #hash 直达时，浏览器可能在脚本执行前已定位；此处补一次高亮。
    if (window.location.hash) {
      var t = document.getElementById(window.location.hash.slice(1));
      if (t) setActive(t.id);
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
}());
JS;
}

// 后台配置页。
function toc_admin(array $plugin): string
{
    if (is_post_request()) {
        $levels = (string)($_POST['levels'] ?? '1,2,3');
        if (!isset(toc_level_options()[$levels])) $levels = '1,2,3';
        plugin_save_config('toc', [
            'levels' => $levels,
            'min' => (string)min(20, max(1, (int)($_POST['min'] ?? 2))),
            'mobile' => (int)($_POST['mobile'] ?? 0) === 1 ? 1 : 0,
        ]);
        set_flash('目录设置已保存');
        go(admin_url(['tab' => 'plugins', 'view' => 'toc']));
    }
    $cfg = toc_config();
    return '<form method="post">' . form_token()
        . '<input type="hidden" name="admin_action" value="noop">'
        . select_input('参与目录的标题层级', 'levels', implode(',', $cfg['levels']), toc_level_options(),
            '文章标题由系统单独渲染在正文外，不会重复；正文里用 # 写的标题属于真实章节，默认参与目录')
        . input('最少标题数', 'min', (string)$cfg['min'], 'number', false, '正文标题少于此数时不显示目录，避免短文出现只有一两项的目录')
        . checkbox('移动端正文顶部显示折叠目录', 'mobile', $cfg['mobile'], '窄屏下侧栏会移到正文之后，改由正文顶部的折叠目录承担')
        . '<button class="btn" type="submit">保存设置</button></form>';
}

return [
    'id' => 'toc',
    'name' => '文章目录',
    'version' => '1.1.0',
    'description' => '为文章与独立页面生成目录：桌面端在右侧栏置顶显示并随滚动高亮，移动端在正文顶部提供折叠目录；自动为标题注入锚点，支持 #深链。',
    'author' => 'Mono',
    'assets' => ['css' => 'toc_css', 'js' => 'toc_js'],
    'hooks' => [
        'markdown.after' => 'toc_process_content',
        'sidebar.stack' => 'toc_sidebar',
    ],
    'admin_tabs' => ['toc' => 'toc_admin'],
];
