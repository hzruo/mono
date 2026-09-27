# Mono

一个轻量、优雅的个人博客程序，遵循「零依赖、单核心、万物皆插件」的设计哲学。
开箱即用：安装完成后即拥有完整的博客能力，还能按需开启评论、主题、点赞等扩展。

## 特点

- **零依赖**：原生 PHP 8.1+，无需 Composer、无需任何第三方框架或前端构建。
- **单文件核心**：博客核心逻辑集中在 `index.php`，`app/optional` 提供安装、后台、插件运行时。
- **跨数据库**：内置数据库抽象层，支持 SQLite / MySQL / PostgreSQL，安装时自由选择。
- **万物皆插件**：评论、主题、点赞等以插件形式存在，启用即用、停用即净，核心始终保持精简。
- **shadcn 风格 UI**：基于设计令牌的现代界面，内置亮色 / 暗色主题。
- **代码块增强**：服务端语法高亮（php/js/css/sql/sh 等）+ 一键复制按钮，零前端依赖。
- **本地随机头像**：根据邮箱/昵称确定性生成 SVG identicon 头像，不依赖任何外部服务。
- **无状态认证**：HMAC-SHA256 签名 Cookie 登录，全站 CSRF 防护。

## 部署

1. 确保服务器支持 PHP 8.1+ 且启用了 PDO（`pdo_sqlite` / `pdo_mysql` / `pdo_pgsql` 按需其一）。
2. 将全部文件上传至网站目录，确保 `app/data` 可写。
3. 访问站点首页，按向导填写数据库与管理员信息，自动完成安装。
4. 安装完成后会自动登录管理员，进入右上角「后台」即可开始写作与管理。

### 本地快速预览

```bash
php -S 127.0.0.1:8080 index.php
```

随后访问 <http://127.0.0.1:8080> 完成安装。

## 目录结构

```
index.php              单文件核心（路由、博客业务、渲染、Hook 管道）
app/optional/          安装(Setup)、后台(Admin)、插件运行时(Plugin)
app/plugins/           插件目录（comment / media / like ...）
app/assets/            核心静态资源（index.css / index.js / index.svg）
app/data/              运行时数据（SQLite 文件、db.php 配置、安装锁）
```

## 插件

在后台「插件」页一键启用或停用，点击「配置」进入各插件独立配置页：

- **评论（comment）**：为文章提供评论、审核与展示能力；评论框带表情面板、实时字数、@回复与 `Ctrl/Cmd+Enter` 快捷发送。
- **文章目录（toc）**：为文章与独立页面生成目录——桌面端右侧栏置顶并随滚动高亮，移动端正文顶部折叠目录，自动注入标题锚点支持深链。
- **点赞（like）**：为文章增加点赞计数（占位符回填，无额外查询开销）。
- **搜索增强（search）**：导航栏常驻搜索框、结果关键词高亮与相关标签推荐。
- **Mermaid 图表（mermaid）**：用 ` ```mermaid ` 代码块绘制流程图/时序图等（官方最新 ESM 渲染）。

插件开发遵循统一的 Hook 契约，详见 `docs/PLUGIN.md`。

## 文档

- 使用指南：[`docs/使用手册.md`](docs/使用手册.md)
- 更新日志：[`CHANGELOG.md`](CHANGELOG.md)（每次修改 `app/version.php` 的版本号必须同步新增条目）
- 插件开发：[`PLUGIN.md`](docs/PLUGIN.md)
- 架构理念：[`DEVELOPMENT_RULES.md`](docs/DEVELOPMENT_RULES.md)
