# 极简原生 PHP 开发规范 (Minimalist Native PHP Standard)

## 1. 核心哲学 (Core Philosophy)
*   **零依赖 (Zero Dependency)**：严禁引入 Composer、框架或第三方库。仅使用 PHP 原生扩展（PDO, JSON, OpenSSL 等）。
*   **单核心 (Single Core)**：所有业务逻辑、路由、工具函数必须收敛在单一入口文件（如 `index.php`）或极少量的核心类中。
*   **数据库抽象 (DB Agnostic)**：核心层必须抹平 SQLite、MySQL、PostgreSQL 的语法差异，实现真正的跨引擎兼容。
*   **契约优于隔离 (Contract over Isolation)**：扩展通过严格的命名规范和 Hook 契约进行，不依赖复杂的沙箱技术。

## 2. 架构约束 (Architecture Constraints)

### 2.1 目录结构
```
/
├── index.php          # 唯一入口：包含路由、工具函数、核心逻辑
├── app/
│   ├── data/          # 运行时数据（数据库、缓存、日志），禁止 Web 访问
│   ├── plugins/       # 插件目录（可选）
│   └── assets/        # 静态资源
└── README.md
```

### 2.2 数据库抽象层 (The DBAL)
必须实现以下核心函数，禁止在业务代码中直接拼接 SQL：
*   `app_db_types($driver)`: 统一字段类型定义（如 `id`, `string`, `text`）。
*   `app_db_identifier($driver, $name)`: 自动处理标识符引用（`` `name` `` vs `"name"`）。
*   `app_db_upsert($table, $data, $keys)`: 跨数据库的 Upsert 逻辑。
*   `q($sql, $params)`: 统一的参数化查询执行器，自带请求级缓存清理。

### 2.3 路由与请求
*   **伪静态支持**：通过解析 `$_SERVER['REQUEST_URI']` 实现路径路由，同时兼容 `?a=action` 查询串模式。
*   **CSRF 防护**：所有 `POST` 请求必须校验 `_csrf` Token。
*   **身份认证**：使用基于 HMAC-SHA256 签名的 Cookie 实现无状态认证，禁止使用 Session。

## 3. 扩展机制 (Extension System)

### 3.1 命名空间契约
为防止冲突，所有扩展资源必须遵循以下前缀规范：
*   **PHP 函数/常量**：`扩展ID_` (如 `hello_get_config`)
*   **CSS 类/变量**：`扩展ID-` (如 `.hello-card`, `--hello-color`)
*   **数据库表**：`ext_扩展ID_` (如 `ext_hello_items`)

### 3.2 Hook 管道模式
*   **定义**：`hook($name, $value, $ctx)`。扩展通过返回新值来修改数据，返回 `null` 表示不修改。
*   **触发**：核心在关键节点（如渲染前、保存后）触发 Hook。
*   **性能红线**：**严禁在循环触发的 Hook（如列表渲染）中执行数据库查询。**

### 3.3 占位符回填机制 (Placeholder Backfill)
针对 N+1 查询问题的标准解决方案：
1.  **埋点**：在循环渲染中插入唯一占位符 `<!--{ID}-{Token}-{PK}-->`。
2.  **收集**：在整页渲染结束前（`page.before_render`），收集所有占位符 ID。
3.  **回填**：执行一次批量 SQL 查询，使用 `preg_replace_callback` 将数据填入 HTML。

## 4. 性能与安全准则 (Performance & Security)

### 4.1 缓存策略
*   **请求级缓存**：利用 `$GLOBALS` 或静态变量存储当前请求中重复读取的数据（如当前用户信息、站点设置）。
*   **懒加载**：配置数据和元数据仅在第一次访问时从数据库加载并缓存。

### 4.2 安全内建
*   **输出转义**：所有输出到 HTML 的内容必须经过 `h()` (htmlspecialchars) 处理。
*   **输入过滤**：所有外部输入（GET/POST/Cookie）必须进行类型转换和白名单校验。
*   **文件安全**：上传文件必须重命名为 Hash 值，并分目录存储。禁止直接执行用户上传的 PHP 文件。

## 5. Agent 开发指令 (Agent Instructions)

当 AI Agent 基于此规范开发时，必须遵循以下流程：

1.  **环境检查**：确认 PHP 版本 >= 8.1，检查 PDO 扩展。
2.  **核心优先**：优先复用 `index.php` 中已有的工具函数（如 `q()`, `h()`, `route_url()`）。
3.  **扩展隔离**：新建扩展时，自动为所有函数、类、CSS 添加扩展 ID 前缀。
4.  **N+1 审查**：在生成列表渲染代码时，自动检查是否存在循环查库，如有则强制使用"占位符回填"或"批量预加载"方案。
5.  **跨库兼容**：编写 SQL 时，必须使用 `app_db_*` 助手，禁止硬编码特定数据库的语法（如 `LIMIT 0,10` 或 `AUTO_INCREMENT`）。
6.  **版本与更新日志**：凡是修改了 `app/assets/*` 或影响前台行为的代码，必须递增 `app/version.php` 的 `APP_VERSION`（静态资源缓存刷新）；**递增版本号的同时必须在 [`CHANGELOG.md`](CHANGELOG.md) 顶部新增对应条目**，二者属于同一次改动。版本号遵循语义化（新功能升次版、纯修复升修订版、破坏性变更升主版），条目按「新增 / 变更 / 修复 / 移除」分组，只写用户可感知的变化。
7.  **能力归属**：文章/分类/标签/用户管理属于核心（`index.php` + `app/optional/`）；评论/搜索/点赞/主题/目录等阅读与交互增强一律做成 `app/plugins/<id>/plugin.php`，优先用现有 Hook 实现零核心改动。

## 6. 示例：如何定义一个跨库数据表
```php
// 错误做法：硬编码 MySQL 语法
$db->exec("CREATE TABLE IF NOT EXISTS posts (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255))");

// 正确做法：使用核心抽象
$t = app_db_types();
app_db_create_table('posts', "id {$t['id']}, title {$t['string']} NOT NULL");
```
