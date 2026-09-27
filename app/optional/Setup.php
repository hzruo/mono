<?php
declare(strict_types=1);

namespace app\optional;

/**
 * 安装与数据库初始化。
 *
 * 负责：渲染安装表单、写入 db.php、创建核心表、创建管理员、写入初始设置、
 * 生成安装锁。核心表使用 app_ 前缀，跨库通过 app_db_* 助手建表。
 */
final class Setup
{
    // 旧安装升级表结构（幂等，可重复执行）。
    public static function upgrade_schema(): void
    {
        $t = app_db_types();
        app_db_ensure_columns('app_users', ['avatar' => "{$t['string']} NOT NULL DEFAULT ''"]);
        app_db_ensure_columns('app_posts', ['allow_comment' => "{$t['uint']} NOT NULL DEFAULT 1"]);
        self::create_pages_table();
        app_db_ensure_columns('app_pages', ['show_title' => "{$t['uint']} NOT NULL DEFAULT 1"]);
    }

    // 独立页面表（关于/友链等，可重复执行）。
    public static function create_pages_table(): void
    {
        $t = app_db_types();
        app_db_create_table('app_pages', "id {$t['id']},slug {$t['string']} NOT NULL,title {$t['string']} NOT NULL,content {$t['text']} NOT NULL,status {$t['uint']} NOT NULL DEFAULT 1,show_title {$t['uint']} NOT NULL DEFAULT 1,sort {$t['uint']} NOT NULL DEFAULT 0,created_at {$t['uint']} NOT NULL,updated_at {$t['uint']} NOT NULL DEFAULT 0,UNIQUE(slug)");
    }

    // 创建全部核心表（可重复执行）。
    public static function create_schema(): void
    {
        $t = app_db_types();

        app_db_create_table('app_users', "id {$t['id']},nickname {$t['string']} NOT NULL,email {$t['string']} NOT NULL,password {$t['string']} NOT NULL,is_admin {$t['uint']} NOT NULL DEFAULT 0,avatar {$t['string']} NOT NULL DEFAULT '',created_at {$t['uint']} NOT NULL");
        app_db_create_table('app_categories', "id {$t['id']},name {$t['string']} NOT NULL,slug {$t['string']} NOT NULL,sort {$t['uint']} NOT NULL DEFAULT 0,UNIQUE(name)");
        app_db_create_table('app_posts', "id {$t['id']},user_id {$t['uint']} NOT NULL,title {$t['string']} NOT NULL,content {$t['text']} NOT NULL,category_id {$t['uint']} NOT NULL DEFAULT 0,status {$t['uint']} NOT NULL DEFAULT 1,view_count {$t['uint']} NOT NULL DEFAULT 0,allow_comment {$t['uint']} NOT NULL DEFAULT 1,created_at {$t['uint']} NOT NULL,updated_at {$t['uint']} NOT NULL DEFAULT 0");
        app_db_create_table('app_tags', "id {$t['id']},name {$t['string']} NOT NULL,slug {$t['string']} NOT NULL,UNIQUE(name)");
        self::create_pages_table();
        app_db_create_table('app_post_tags', "post_id {$t['uint']} NOT NULL,tag_id {$t['uint']} NOT NULL,PRIMARY KEY (post_id,tag_id)");
        app_db_create_table('app_settings', "name {$t['key']} NOT NULL PRIMARY KEY,value {$t['text']}");
        app_db_create_table('app_plugins', "id {$t['key']} NOT NULL PRIMARY KEY,name {$t['string']} NOT NULL,version {$t['string']} NOT NULL,file {$t['string']} NOT NULL,code_hash {$t['string']} NOT NULL,manifest_json {$t['text']},config_json {$t['text']},entries_json {$t['text']},enabled {$t['uint']} NOT NULL DEFAULT 0,status {$t['string']} NOT NULL DEFAULT 'disabled',disabled_reason {$t['string']},installed_at {$t['uint']} NOT NULL DEFAULT 0,updated_at {$t['uint']} NOT NULL DEFAULT 0");

        app_db_create_index('idx_posts_status_created', 'app_posts (status,created_at)');
        app_db_create_index('idx_posts_category', 'app_posts (category_id)');
        app_db_create_index('idx_tags_name', 'app_tags (name)');
        app_db_create_index('idx_post_tags_tag', 'app_post_tags (tag_id)');
    }

    // 执行安装：写配置、建表、建管理员、写初始设置、生成锁。
    public static function install_run(): void
    {
        $driver = (string)($_POST['driver'] ?? 'sqlite');
        if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) $driver = 'sqlite';
        $config = ['driver' => $driver];
        if ($driver === 'sqlite') {
            $config['database'] = (string)($_POST['db_name'] ?? '') !== '' ? (string)$_POST['db_name'] : 'blog.sqlite';
        } else {
            $config['host'] = (string)($_POST['host'] ?? '127.0.0.1');
            $config['port'] = (string)($_POST['port'] ?? ($driver === 'mysql' ? '3306' : '5432'));
            $config['database'] = (string)($_POST['database'] ?? '');
            $config['username'] = (string)($_POST['username'] ?? '');
            $config['password'] = (string)($_POST['password'] ?? '');
        }

        $nickname = trim((string)($_POST['nickname'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password_admin'] ?? '');
        if ($nickname === '' || $email === '' || strlen($password) < 4) {
            self::install_page('请完整填写管理员信息，密码至少 4 位。');
            exit;
        }

        if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
        if (!is_writable(DATA_DIR)) { self::install_page('app/data 目录不可写，请检查权限。'); exit; }

        // 写入数据库配置（覆盖旧配置以支持重装）。
        // 注意：本请求此前未触发任何 db()/db_config() 调用，故首次连接即读取新配置。
        file_put_contents(DB_CONFIG_FILE, '<?php return ' . var_export($config, true) . ';', LOCK_EX);

        try {
            self::create_schema();
            // 创建管理员（幂等：已存在同邮箱则更新密码）。
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $exists = one('SELECT id FROM app_users WHERE email=?', [$email]);
            if ($exists) {
                q('UPDATE app_users SET nickname=?,password=?,is_admin=1 WHERE id=?', [$nickname, $hash, (int)$exists['id']]);
                $admin_id = (int)$exists['id'];
            } else {
                q('INSERT INTO app_users(nickname,email,password,is_admin,created_at) VALUES(?,?,?,?,?)', [$nickname, $email, $hash, 1, now()]);
                $admin_id = app_db_last_insert_id('app_users');
            }
            // 初始设置。
            save_settings_values([
                'site_name' => (string)($_POST['site_name'] ?? '') !== '' ? (string)$_POST['site_name'] : 'Mono',
                'site_description' => (string)($_POST['site_description'] ?? '一个简洁优雅的个人博客'),
            ]);
            // 默认分类与欢迎文章。
            if ((int)val('SELECT COUNT(*) FROM app_categories') === 0) {
                q('INSERT INTO app_categories(name,slug,sort) VALUES(?,?,?)', ['默认分类', 'default', 0]);
            }
            if ((int)val('SELECT COUNT(*) FROM app_posts') === 0) {
                $cat_id = (int)val('SELECT id FROM app_categories ORDER BY id ASC LIMIT 1');
                $welcome = <<<'MD'
# 欢迎来到 Mono

这是系统为你创建的第一篇文章，登录后可以在后台随时编辑或删除它。

Mono 是一个轻量、优雅的个人博客，安装即用：写作、分类、标签、归档、搜索一应俱全，还能按需开启评论、主题换肤等扩展。

## 三步上手

1. 点击右上角「后台」进入管理面板
2. 到「插件」页开启你需要的功能，例如评论、主题
3. 点击「写文章」，用 Markdown 记录你的想法

## Markdown 速览

Mono 支持常见的 Markdown 写法：**加粗**、*斜体*、`行内代码`、标题、列表、引用、链接与图片，以及代码块：

```php
echo "Hello, Mono!";
```

> 愿这里成为你安静书写的一隅。
MD;
                q('INSERT INTO app_posts(user_id,title,content,category_id,status,view_count,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)',
                    [$admin_id, '欢迎来到 Mono', $welcome, $cat_id, 1, 0, now(), now()]);
            }
            // 标记插件目录待同步，并生成安装锁。
            save_settings_values(['plugin_sync_pending' => '1', 'plugin_assets_dirty' => '1']);
            file_put_contents(INSTALL_LOCK_FILE, date('c'), LOCK_EX);
        } catch (\Throwable $e) {
            @unlink(INSTALL_LOCK_FILE);
            self::install_page('数据库初始化失败：' . $e->getMessage());
            exit;
        }

        // 自动登录管理员并跳转首页。
        start_login($admin_id);
        set_flash('安装成功，欢迎使用 Mono！');
        go(route_url('home'));
    }

    // 渲染安装页。
    public static function install_page(string $error = ''): void
    {
        if (db_schema_ready() && ($_GET['force'] ?? '') !== '1') { go(route_url('home')); }
        $css = asset_url('app/assets/index.css');
        $err = $error !== '' ? '<div class="flash" style="background:var(--danger-soft);color:var(--danger);border-color:#fecaca">' . h($error) . '</div>' : '';
        echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>安装 Mono</title><link rel="stylesheet" href="' . h($css) . '?v=' . h(APP_VERSION) . '"></head><body>'
            . '<div class="install-wrap"><div class="card"><h1 style="margin-top:0">安装 Mono</h1>' . $err
            . '<div class="note">SQLite 会自动创建数据库文件；MySQL / PostgreSQL 请提前建好数据库。安装完成后会自动登录管理员。</div>'
            . '<form method="post" action="' . h(index_url(['a' => 'install'])) . '">'
            . '<input type="hidden" name="_csrf" value="install">'
            . select_input('数据库类型', 'driver', 'sqlite', ['sqlite' => 'SQLite', 'mysql' => 'MySQL', 'pgsql' => 'PostgreSQL'])
            . '<div id="sqlite-config">'
            . input('SQLite 文件名', 'db_name', 'blog.sqlite', 'text', false, '数据库文件将创建在 app/data 目录下')
            . '</div>'
            . '<div id="sql-config" hidden>'
            . '<div class="form-grid-2">' . input('数据库地址', 'host', '127.0.0.1') . input('端口', 'port', '3306') . '</div>'
            . input('数据库名', 'database') . '<div class="form-grid-2">' . input('用户名', 'username') . input('密码', 'password', '', 'password') . '</div>'
            . '</div>'
            . '<hr style="border:0;border-top:1px solid var(--line);margin:20px 0">'
            . '<div class="card-title">站点与管理员</div>'
            . '<div class="form-grid-2">' . input('站点名称', 'site_name', 'Mono') . input('站点描述', 'site_description', '一个简洁优雅的个人博客') . '</div>'
            . '<div class="form-grid-2">' . input('管理员昵称', 'nickname', '', 'text', true) . input('管理员邮箱', 'email', '', 'email', true) . '</div>'
            . input('管理员密码', 'password_admin', '', 'password', true, '至少 4 位')
            . '<button class="btn" type="submit">开始安装</button>'
            . '</form></div></div>'
            . '<script>(function(){var d=document.querySelector(\'[name=driver]\');function t(){var s=d.value===\'sqlite\';document.getElementById(\'sqlite-config\').hidden=!s;document.getElementById(\'sql-config\').hidden=s;}d.addEventListener(\'change\',t);t();})();</script>'
            . '</body></html>';
        exit;
    }
}
