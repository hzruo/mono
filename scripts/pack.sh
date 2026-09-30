#!/usr/bin/env bash
# ------------------------------------------------------------------
# Monoblog 部署包构建脚本
#
# 用法：bash scripts/pack.sh [--force]
#   --force  工作区存在未提交的跟踪改动时仍继续（改动不会进入包）
#
# 产物：dist/monoblog-v<版本>-<日期>.zip
#   - 由 git archive HEAD 生成：只含 git 跟踪文件，天然排除运行时数据
#     （app/data/、.env、plugins.css|js 合并产物、.git、系统文件）
#   - scripts/、docs/、CHANGELOG.md 经 .gitattributes export-ignore 排除，不进部署包
#   - 无顶层前缀目录，解压即得网站根内容（上传至网站根目录后自动安装）
# ------------------------------------------------------------------
set -euo pipefail
cd "$(dirname "$0")/.."

# 1. 版本号（sed 提取，避免 php -r 输出被本机 rdkafka 告警污染）
VERSION="$(sed -n "s/.*APP_VERSION', '\([^']*\)'.*/\1/p" app/version.php)"
if [ -z "$VERSION" ]; then
    echo "错误：无法从 app/version.php 提取 APP_VERSION" >&2
    exit 1
fi

# 2. 工作区检查：包内容取自 HEAD，未提交的跟踪改动会缺席
DIRTY="$(git status --porcelain | grep -vE '^\?\?' || true)"
if [ -n "$DIRTY" ]; then
    echo "警告：存在未提交的跟踪改动，以下改动不会进入部署包：" >&2
    echo "$DIRTY" >&2
    if [ "${1:-}" != "--force" ]; then
        echo "请先提交后再打包；确需跳过请使用 --force。" >&2
        exit 1
    fi
fi

# 3. 生成部署包（--worktree-attributes：让工作区中未提交的 .gitattributes 排除规则也即时生效）
DATE="$(date +%Y%m%d)"
OUT="dist/monoblog-v${VERSION}-${DATE}.zip"
mkdir -p dist
git archive --worktree-attributes --format=zip -o "$OUT" HEAD

# 4. 校验包内清单
LIST="$(unzip -Z1 "$OUT")"

# 4.1 必须排除：运行时数据 / 密钥 / 合并产物 / 开发脚本 / 系统文件
EXCLUDE_PAT='^app/data/|^\.env$|^app/assets/plugins\.(css|js)$|^\.git/|^scripts/|^docs/|^CHANGELOG\.md$|DS_Store'
BAD="$(printf '%s\n' "$LIST" | grep -E "$EXCLUDE_PAT" || true)"
if [ -n "$BAD" ]; then
    echo "错误：部署包出现应排除的文件：" >&2
    echo "$BAD" >&2
    exit 1
fi

# 4.2 关键文件必须存在
for f in index.php .htaccess app/version.php .env.example; do
    if ! printf '%s\n' "$LIST" | grep -qxF "$f"; then
        echo "错误：部署包缺少关键文件：$f" >&2
        exit 1
    fi
done

# 5. 汇总
FILES="$(printf '%s\n' "$LIST" | grep -vc '/$' || true)"
SIZE="$(wc -c < "$OUT" | tr -d ' ')"
echo "--------------------------------------------------"
echo "部署包生成成功"
echo "  产物：${OUT}"
echo "  版本：v${VERSION}（提交 $(git rev-parse --short HEAD)）"
echo "  文件：${FILES} 个，${SIZE} 字节"
echo "  排除：app/data、.env、plugins.css|js、.git、scripts/、docs/、CHANGELOG.md、系统文件"
echo "  部署：解压到网站根目录 → 访问域名自动安装"
