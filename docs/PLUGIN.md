# Mono 插件开发指南

面向插件开发者。Mono 的扩展机制遵循 `DEVELOPMENT_RULES.md` 的「契约优于隔离」：
插件是一个目录 + 一个返回 manifest 数组的 `plugin.php`，通过命名规范与 Hook 契约与核心协作，不使用沙箱。

## 1. 目录与最小结构

```
app/plugins/<插件ID>/
└── plugin.php      # 唯一入口：定义函数并 return manifest 数组
```

- 插件 ID 用小写字母/数字/下划线，如 `comment`、`like`、`media`。
- 把 `plugin.php` 放入 `app/plugins/<ID>/` 即被识别：核心每次请求都会扫描插件目录并比对注册表，新增目录、删除目录、`plugin.php` 内容变更（sha256 不一致）三种漂移都会自动重同步并重建合并资源——开发时改完代码刷新页面即生效，无需手动「同步」；新插件仍需到后台「插件」页**启用**。
- **合并资源自检（排查「点击没反应」）**：各插件 `assets js` 按**插件 ID 字母序**合并进同一个 `app/assets/plugins.js`（每段以 `/* 插件ID */` 注释开头）——任意一段有语法错误都会让**整个文件**解析失败、所有插件脚本统统不执行（典型症状：评论工具栏等插件交互「点击没反应」且页面无明显报错）。在 heredoc 中手写 JS 时，正则与字符串里的换行/引号等转义必须保持字面的反斜杠写法；改完插件 JS 后先触发一次页面请求重建产物，再执行 `node --check app/assets/plugins.js` 自检。
- 启用/停用/卸载均在后台「插件」页完成，无需改代码。

## 2. manifest 字段

`plugin.php` 末尾 `return` 一个数组：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | string | 插件 ID，与目录名一致 |
| `name` | string | 后台显示名称 |
| `version` | string | 语义化版本 |
| `description` | string | 一句话简介 |
| `author` | string | 作者 |
| `hooks` | array | `Hook 名 => 回调函数名` |
| `entries` | array | 可选：为 4 个预定义条目 `nav_links` / `sidebar_cards` / `post_actions` / `admin_tabs`（对应 `nav.menu_links` / `sidebar.stack` / `post.actions` / `admin.tabs`）声明默认开关，值为 `false` 时对应 Hook 回调不注册（默认开启） |
| `routes` | array | `路由名 => 回调函数名`（回调签名为 `fn(array $plugin): void`） |
| `admin_tabs` | array | `子页ID => 回调函数名`（后台配置页，回调返回 HTML 字符串） |
| `assets` | array | `css`/`js` => 回调函数名，返回字符串，合并进 `app/assets/plugins.css/js` |
| `install` | string | 启用时调用的建表/初始化函数 `fn(array $plugin): void` |
| `uninstall` | string | 卸载时调用的清理函数 `fn(array $plugin, bool $keep_data): void` |
| `cron` | array | `任务名 => ['callback' => 函数名, 'interval' => 秒数（≥60）或返回秒数的函数名]`，回调签名 `fn(array $plugin, array $task)`；由外部 cron 调用入口触发：CLI `php index.php cron` 无需令牌；HTTP `/index.php?a=cron&token=…` 必须携带访问令牌（建议用核心助手 `cron_cli_command()` / `cron_secret_url()` 生成展示内容） |

示例（节选自 `like` 插件）：

```php
return [
    'id' => 'like',
    'name' => '文章点赞',
    'version' => '1.0.2',
    'description' => '为文章增加点赞功能……',
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
```

## 3. 命名空间契约（防冲突）

- PHP 函数/常量：`插件ID_` 前缀，如 `like_toggle()`、`comment_config()`。同步时核心会扫描插件定义的全部 PHP 函数并与核心/其它插件比对，重名即判定冲突、插件不可启用。
- CSS 类/变量：`插件ID-` 前缀，如 `.like-btn`、`--comment-gap`。
- 数据库表：`plugin_插件ID_` 前缀，如 `plugin_comment_comments`、`plugin_like_likes`。

## 4. Hook 管道

核心在关键节点触发 Hook。两类签名：

- **改数据**：`hook($name, $value, $ctx)` —— 回调 `fn($value, array $ctx)`，返回新值即修改，返回 `null`/原值表示不改。
- **触发动作**：`fire($name, $ctx)` —— 回调 `fn(array $ctx)`，无返回值。

核心当前提供的 Hook（以 `index.php` 实际触发点为准）：

| Hook | 类型 | 时机 |
| --- | --- | --- |
| `app.boot` | fire | 请求分发前 |
| `request.csrf_exempt` | hook(bool) | 返回 true 可豁免某路由的 CSRF 校验 |
| `markdown.render` / `markdown.after` | hook | Markdown 渲染前后 |
| `page.template.before` / `page.template` | hook | 整页 HTML 模板生成前后（`page.template` 可改 `<html>` 标签，如暗色类） |
| `page.head` | hook | `<head>` 内容（可注入 `<style>`/令牌覆盖） |
| `page.header` / `page.footer` | hook | 页眉/页脚 |
| `page.seo` | hook | SEO meta |
| `page.before_render` | hook | 整页渲染结束前（占位符回填的标准时机） |
| `nav.menu_links` / `top.bar.actions` / `sidebar.stack` | hook | 导航/顶栏/侧边栏追加内容 |
| `post.list.after_render` | hook | 文章列表渲染后（循环埋点场景） |
| `post.content_after` | hook | 文章正文后（如评论区） |
| `post.actions` | hook | 文章操作区（如点赞按钮） |
| `post.before_save` / `post.after_save` | hook/fire | 保存文章前后 |
| `post.before_delete` / `post.after_delete` | hook/fire | 删除文章前后 |
| `login.after_form` | hook | 登录表单后 |
| `admin.tabs` | hook | 后台标签栏追加标签（`[标签键 => 标签名]`；键须与插件 `admin_tabs` 子页键一致，点击标签渲染该回调内容并自动包卡片） |

**性能红线**：严禁在循环触发的 Hook（如 `post.list.after_render`）中查库，请使用下面的占位符回填。

**容错红线**：任一插件回调（hook / route / assets / admin_tabs / cron / install / uninstall）抛出未捕获异常时，核心会自动**停用该插件**（`status=error`，原因写入 `disabled_reason`，后台可见），需重新同步后才能再启用——一次异常即整个插件下线。回调内应自行 `try/catch` 兜底（出错时返回原值或空串），不要把异常抛给核心。

## 5. 占位符回填（解决 N+1）

列表渲染需要逐条查库的数据时：

1. **埋点**：循环中输出占位符 `<!--like-{token}-{id}-->`（token 为请求内随机串）。
2. **收集+回填**：在 `page.before_render` 回调里用正则收集全部 ID，一次 `IN` 查询，再 `preg_replace_callback` 整页替换。

参考 `like` 插件的 `like_list_render`（埋点）与 `like_backfill`（回填）。

## 6. 后台配置页（admin_tabs）

回调返回表单 HTML。表单需包含 `form_token()` 与
`<input type="hidden" name="admin_action" value="noop">`，提交后核心会把 POST 交回该回调，
回调内自行判断 `is_post_request()` 并用 `plugin_save_config($id, $array)` 持久化、`go()` 跳转。
读取配置用 `plugin_config($id, $default)`。参考 `paper` 插件的 `paper_admin()`。

若希望某子页同时作为后台导航的**常驻标签**出现，在 `admin.tabs` Hook 中声明同名键即可——点击标签（`?a=admin&tab=键`）会渲染该子页回调，内容自动包在卡片中（标题取标签名）：

```php
function my_admin_tabs(array $tabs, array $ctx): array { $tabs['my'] = '我的'; return $tabs; }
// manifest：'hooks' => ['admin.tabs' => 'my_admin_tabs'], 'admin_tabs' => ['my' => 'my_admin']
```

回调内处理 POST 时可用 `$_GET['tab']` 判断从标签还是插件配置页进入，`go()` 跳回对应位置。

## 7. 建表与查询

一律使用核心 DBAL 助手，禁止硬编码某数据库语法：

```php
function my_install(array $plugin): void
{
    $t = app_db_types();
    app_db_create_table('plugin_my_items', "id {$t['id']},post_id {$t['uint']} NOT NULL,UNIQUE(post_id)");
}
```

查询用 `q()` / `all()` / `one()` / `val()` / `row()`，Upsert 用 `app_db_upsert($table, $data, $keys)`
（`$keys` 列必须有 UNIQUE 约束）。

## 8. 安全与输出

- 所有输出到 HTML 的内容必须 `h()` 转义。
- 所有输入做类型转换与白名单校验（`post()` / `(int)` 等）。
- 后台 POST 表单带 `form_token()`；核心已对全部 POST 做 CSRF 校验。

## 9. 主题类插件（换肤 / 布局）

主题类插件（如 `paper`）不新增数据与交互，只改变站点观感。与功能插件的差异：不建表、不走路由，冲突面从「命名空间与 DB」变成 **CSS 层叠**。

**渲染三件套**：

| 位置 | 用途 |
| --- | --- |
| `page.head`（hook） | 注入 `<style data-plugin-id="<ID>">`，覆盖语义令牌（配色、圆角、字体变量） |
| `page.template`（hook） | 修改 `<html>` 标签属性（如追加 `.dark` 类实现默认暗色） |
| `assets css`（manifest） | 结构性样式（布局、组件外观），合并进 `plugins.css` |

**令牌优先**：只覆盖 `index.css` 的语义令牌（`--primary` / `--background` / `--border` / `--radius` ...），核心组件规则会自动换肤，与其它插件天然兼容；只有布局级改造（如侧栏下移）才写组件选择器，并注意下面的层叠规则。亮色令牌写 `:root{}`、暗色写 `.dark{}`（先亮后暗）——核心通过 `<html class="dark">` 切换深浅色，主题不必自己实现切换逻辑。

**`page.template` 必须幂等**：直接 `preg_replace` 追加类名时，若 `<html>` 已有 class 属性会输出重复的 class 属性，需先判断再追加（三种情况：已有 `.dark` → 不动；已有其它 class → 追加 `dark `；无 class → 新加属性）：

```php
if (preg_match('/<html[^>]*\bclass="[^"]*\bdark\b/i', $v)) return $v;
if (preg_match('/<html[^>]*\bclass="/i', $v)) {
    return preg_replace('/(<html[^>]*\bclass=")/i', '$1dark ', $v, 1) ?? $v;
}
return preg_replace('/<html(\s[^>]*)?>/i', '<html$1 class="dark">', $v, 1) ?? $v;
```

**样式生效顺序（层叠链）**：同一页面的 CSS 按以下顺序进入文档，同特异性下后写胜出——

1. `index.css`（核心）
2. `plugins.css`：各插件 `assets css` 按**插件 ID 字母序**合并，每段以 `/* 插件ID */` 注释开头
3. `page.head` 注入的内联 `<style>`：整体晚于 `plugins.css`（同样按 ID 字母序拼接）

因此：令牌覆盖放 `page.head` 最稳；结构性样式放 `assets css`，与其它插件在 `plugins.css` 内竞争——要覆盖核心或其它插件的既有规则，**显式提高特异性**，不要赌加载顺序。例（paper 让 toc 的折叠目录在桌面端显示）：

```css
.wrap .toc-inline{display:block}                          /* 0,2,0，盖过 toc 的 0,1,0 桌面隐藏 */
.wrap:has(.toc-inline) .sidebar .toc-card{display:none}   /* :has 条件隐藏，避免误伤 */
```

**共存注意**：同一时间只启用一个主题类插件（各自注入令牌，字母序靠后者覆盖前者，混用结果不可预期）；主题无 `install` / `uninstall`、不改数据，停用即完全还原——保持这个特性。

参考实现：`paper`（整站主题：令牌 + 布局改造 + 首页引言）。
