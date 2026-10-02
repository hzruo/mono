<?php
declare(strict_types=1);

namespace app\optional;

/**
 * 插件管理核心（万物皆插件）。
 *
 * 职责：
 * - plugin_runtime_cache_rows：从持久化缓存/数据库读取已启用插件的运行时行（普通请求不扫描目录）。
 * - plugin_registry_sync：扫描 app/plugins 目录，校验 manifest，写入 app_plugins 注册表。
 * - plugin_set_enabled / plugin_uninstall：启用（含 install）、停用、卸载（含 uninstall）。
 * - plugin_assets_rebuild：合并所有启用插件的 css/js 到 app/assets/plugins.{css,js}。
 * - plugin_function_conflicts：静态检测插件间函数重名，避免致命冲突。
 * - cron_run_due_tasks：统一计划任务调度。
 */
final class Plugin
{
    private static array $registry_cache = [];

    // --- 运行时缓存行（供 index.php plugins() 使用）---
    private static function runtime_rows_valid(array $rows): bool
    {
        $fields = array_fill_keys(['id', 'file', 'config', 'entries', 'hooks', 'routes', 'admin_tabs', 'assets', 'cron'], true);
        foreach ($rows as $row) {
            if (!is_array($row) || array_diff_key($row, $fields) || array_diff_key($fields, $row)) return false;
            $id = (string)$row['id'];
            $file = str_replace('\\', '/', ltrim((string)$row['file'], '/'));
            if (!plugin_id_valid($id) || $file !== 'app/plugins/' . $id . '/plugin.php') return false;
            foreach (['config', 'entries', 'hooks', 'routes', 'admin_tabs', 'assets', 'cron'] as $f) {
                if (!is_array($row[$f])) return false;
            }
        }
        return true;
    }

    public static function plugin_runtime_cache_rows(bool $refresh = false): array
    {
        $rows = $refresh ? null : json_decode(setting('cache_plugins'), true);
        if (is_array($rows) && self::runtime_rows_valid($rows)) return $rows;
        $rows = [];
        foreach (q('SELECT id,file,manifest_json,config_json,entries_json FROM app_plugins WHERE enabled=1 ORDER BY id')->fetchAll() as $row) {
            $id = (string)($row['id'] ?? '');
            $file = str_replace('\\', '/', ltrim((string)($row['file'] ?? ''), '/'));
            $manifest = plugin_json_decode($row['manifest_json'] ?? '', null);
            if (!plugin_id_valid($id) || $file !== 'app/plugins/' . $id . '/plugin.php' || $manifest === null) continue;
            $rows[] = [
                'id' => $id,
                'file' => $file,
                'config' => plugin_json_decode($row['config_json'] ?? '') ?? [],
                'entries' => plugin_json_decode($row['entries_json'] ?? '') ?? [],
                'hooks' => is_array($manifest['hooks'] ?? null) ? $manifest['hooks'] : [],
                'routes' => is_array($manifest['routes'] ?? null) ? $manifest['routes'] : [],
                'admin_tabs' => is_array($manifest['admin_tabs'] ?? null) ? $manifest['admin_tabs'] : [],
                'assets' => is_array($manifest['assets'] ?? null) ? $manifest['assets'] : [],
                'cron' => is_array($manifest['cron'] ?? null) ? $manifest['cron'] : [],
            ];
        }
        save_settings_values(['cache_plugins' => plugin_json_encode($rows)]);
        return $rows;
    }

    // --- 注册表读取 ---
    public static function plugin_registry(?string $id = null, bool $refresh = false): array
    {
        if ($id !== null && !plugin_id_valid($id)) return [];
        if ($refresh) self::$registry_cache = [];
        $key = $id ?? '*';
        if (isset(self::$registry_cache[$key])) return self::$registry_cache[$key];
        $plugins = [];
        $sql = 'SELECT id,name,version,file,manifest_json,config_json,entries_json,enabled,disabled_reason,updated_at FROM app_plugins';
        $rows = q($sql . ($id === null ? ' ORDER BY id' : ' WHERE id=?'), $id === null ? [] : [$id])->fetchAll();
        foreach ($rows as $row) {
            $plugin = self::registry_row($row);
            if ($plugin) $plugins[(string)$plugin['id']] = $plugin;
        }
        return self::$registry_cache[$key] = $plugins;
    }

    private static function registry_row(array $row): ?array
    {
        $id = (string)($row['id'] ?? '');
        $file = str_replace('\\', '/', ltrim((string)($row['file'] ?? ''), '/'));
        $manifest = plugin_json_decode($row['manifest_json'] ?? '', null);
        if (!plugin_id_valid($id) || $file !== 'app/plugins/' . $id . '/plugin.php' || $manifest === null) return null;
        return array_merge($manifest, [
            'id' => $id,
            'name' => (string)$row['name'],
            'version' => (string)$row['version'],
            'enabled' => (int)$row['enabled'] === 1,
            'status' => '',
            'disabled_reason' => (string)($row['disabled_reason'] ?? ''),
            'updated_at' => (int)($row['updated_at'] ?? 0),
            'file' => APP_ROOT . '/' . $file,
            'config' => plugin_json_decode($row['config_json'] ?? '') ?? [],
            'entries' => plugin_json_decode($row['entries_json'] ?? '') ?? [],
        ]);
    }

    public static function plugin_update_row(string $id, array $values, bool $touch = true): void
    {
        if (!plugin_id_valid($id)) throw new \InvalidArgumentException('插件 ID 无效');
        $allowed = array_flip(['name', 'version', 'file', 'code_hash', 'manifest_json', 'config_json', 'entries_json', 'enabled', 'status', 'disabled_reason', 'installed_at']);
        if (array_diff_key($values, $allowed)) throw new \InvalidArgumentException('插件字段无效');
        foreach (['manifest_json', 'config_json', 'entries_json'] as $f) {
            if (array_key_exists($f, $values)) $values[$f] = plugin_json_encode(plugin_json_decode($values[$f]) ?? []);
        }
        if (!$values) return;
        if ($touch) $values['updated_at'] = now();
        $fields = array_keys($values);
        q('UPDATE app_plugins SET ' . implode('=?,', $fields) . '=? WHERE id=?', array_merge(array_values($values), [$id]));
    }

    // --- manifest 校验 ---
    public static function plugin_manifest_validate(array $plugin, string $file): ?array
    {
        $id = (string)($plugin['id'] ?? '');
        if (!plugin_id_valid($id) || $id !== basename(dirname($file))) return null;
        $base = [
            'id' => $id,
            'name' => (string)($plugin['name'] ?? $id),
            'version' => (string)($plugin['version'] ?? ''),
            'description' => (string)($plugin['description'] ?? ''),
            'author' => (string)($plugin['author'] ?? ''),
            'hooks' => is_array($plugin['hooks'] ?? null) ? $plugin['hooks'] : [],
            'routes' => is_array($plugin['routes'] ?? null) ? $plugin['routes'] : [],
            'admin_tabs' => is_array($plugin['admin_tabs'] ?? null) ? $plugin['admin_tabs'] : [],
            'assets' => is_array($plugin['assets'] ?? null) ? $plugin['assets'] : [],
            'cron' => is_array($plugin['cron'] ?? null) ? $plugin['cron'] : [],
            'entries' => is_array($plugin['entries'] ?? null) ? $plugin['entries'] : [],
            'install' => (string)($plugin['install'] ?? ''),
            'uninstall' => (string)($plugin['uninstall'] ?? ''),
            'file' => $file,
        ];
        if ($base['version'] === '') return null;
        foreach (['hooks', 'routes', 'admin_tabs'] as $map) {
            $items = [];
            foreach ($base[$map] as $name => $fn) {
                if (is_string($name) && is_string($fn) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $fn)) $items[$name] = $fn;
            }
            $base[$map] = $items;
        }
        $entries = [];
        foreach ($base['entries'] as $entry => $enabled) {
            $entry = (string)$entry;
            $hook = plugin_entry_hook_name($entry);
            if ($hook !== '' && isset($base['hooks'][$hook])) $entries[$entry] = !empty($enabled);
        }
        $base['entries'] = $entries;
        $assets = [];
        foreach (['css', 'js'] as $type) {
            $fn = $base['assets'][$type] ?? null;
            if (is_string($fn) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $fn)) $assets[$type] = $fn;
        }
        $base['assets'] = $assets;
        $cron = [];
        foreach ($base['cron'] as $name => $task) {
            if (!is_string($name) || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $name) !== 1 || !is_array($task)) continue;
            $callback = (string)($task['callback'] ?? '');
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $callback) !== 1) continue;
            $interval = $task['interval'] ?? 0;
            if (is_string($interval) && !is_numeric($interval)) {
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $interval) !== 1) continue;
            } else {
                $interval = min(31536000, max(60, (int)$interval));
            }
            $cron[$name] = ['callback' => $callback, 'interval' => $interval];
        }
        $base['cron'] = $cron;
        foreach (['install', 'uninstall'] as $k) if ($base[$k] !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $base[$k]) !== 1) $base[$k] = '';
        return $base;
    }

    // --- 目录扫描与冲突检测 ---
    public static function plugin_files(): array
    {
        $files = glob(PLUGIN_DIR . '/*/plugin.php') ?: [];
        sort($files);
        return array_values(array_filter($files, 'is_file'));
    }

    /**
     * 提取插件文件里定义的真实 PHP 函数名，用于检测跨插件重名（含核心函数）。
     * 必须用 token_get_all 而非正则：插件常把 CSS/JS 放在 heredoc 里返回，其中的 JS `function init()`
     * 会被正则误判为 PHP 函数，导致两个插件因同名内部函数而被误报冲突、遭自动禁用。
     */
    private static function plugin_file_functions(string $file): array
    {
        $code = (string)file_get_contents($file);
        if ($code === '') return [];
        $functions = [];
        try {
            $tokens = token_get_all($code, TOKEN_PARSE);
        } catch (\Throwable) {
            // 语法错误时退回正则粗筛（随后 include 会给出准确报错）。
            if (preg_match_all('/^\s*function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m', $code, $m)) {
                foreach ($m[1] as $fn) $functions[] = $fn;
            }
            return $functions;
        }
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_FUNCTION) continue;
            // 取 function 后的首个有效 token：T_STRING 才是具名函数，'(' 为匿名闭包（跳过）。
            for ($j = $i + 1; $j < $n; $j++) {
                $nt = $tokens[$j];
                if (is_array($nt) && in_array($nt[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
                if (is_array($nt) && $nt[0] === T_STRING) $functions[] = (string)$nt[1];
                break;
            }
        }
        return array_values(array_unique($functions));
    }

    /**
     * 判断函数是否由插件目录内的文件定义（而非核心/内置）。
     * 用于冲突检测：后台「同步插件」发生在 app.boot 加载本插件之后时，插件自己的函数
     * 也已存在，不能据此判为「与核心冲突」。
     */
    private static function plugin_fn_defined_in_plugins(string $fn): bool
    {
        try {
            $src = (new \ReflectionFunction($fn))->getFileName();
        } catch (\Throwable) {
            return false;
        }
        if (!is_string($src) || $src === '') return false;
        $dir = rtrim(str_replace('\\', '/', PLUGIN_DIR), '/') . '/';
        return str_starts_with(str_replace('\\', '/', $src), $dir);
    }

    public static function plugin_function_conflicts(array $files): array
    {
        $seen = [];      // fn => file
        $conflicts = []; // file => [messages]
        foreach ($files as $file) {
            foreach (self::plugin_file_functions($file) as $fn) {
                if (function_exists($fn) && !isset($seen[$fn])) {
                    // function_exists 只能证明函数已定义，不能证明它由核心定义：同步可能运行在
                    // 插件已加载之后（如后台「同步插件」在 app.boot 之后触发），此时插件自身的
                    // 函数也在函数表中，直接判核心冲突会把整个插件误禁用。用反射确认定义来源，
                    // 由插件文件定义的跳过此判定（跨插件重名仍由下方 $seen 分支捕捉）。
                    if (!self::plugin_fn_defined_in_plugins($fn)) {
                        $conflicts[$file][] = "函数 $fn 与核心或其它插件冲突";
                        continue;
                    }
                }
                if (isset($seen[$fn])) {
                    $conflicts[$file][] = "函数 $fn 与 " . basename(dirname($seen[$fn])) . " 冲突";
                    continue;
                }
                $seen[$fn] = $file;
            }
        }
        return $conflicts;
    }

    // --- 注册同步 ---
    public static function plugin_registry_sync(): array
    {
        $existing = [];
        foreach (q('SELECT * FROM app_plugins')->fetchAll() as $row) $existing[(string)$row['id']] = $row;
        $synced = [];
        $disable = function (string $id, string $file, string $reason) use (&$existing, &$synced): void {
            if (!plugin_id_valid($id)) return;
            $old = $existing[$id] ?? [];
            $code_hash = is_file($file) ? (hash_file('sha256', $file) ?: '') : '';
            app_db_upsert('app_plugins', [
                'id' => $id,
                'name' => (string)($old['name'] ?? $id),
                'version' => (string)($old['version'] ?? ''),
                'file' => ltrim(str_replace(APP_ROOT, '', $file), '/'),
                'code_hash' => $code_hash,
                'manifest_json' => (string)($old['manifest_json'] ?? '{}'),
                'config_json' => (string)($old['config_json'] ?? '{}'),
                'entries_json' => (string)($old['entries_json'] ?? '{}'),
                'enabled' => 0,
                'status' => 'error',
                'disabled_reason' => $reason,
                'installed_at' => (int)($old['installed_at'] ?? 0) ?: now(),
                'updated_at' => (string)($old['code_hash'] ?? '') === $code_hash ? ((int)($old['updated_at'] ?? 0) ?: now()) : now(),
            ], ['id']);
            $synced[$id] = true;
        };

        $files = self::plugin_files();
        $conflicts = self::plugin_function_conflicts($files);
        foreach ($files as $file) {
            $id = basename(dirname($file));
            if (isset($conflicts[$file])) { $disable($id, $file, implode('；', $conflicts[$file])); continue; }
            try {
                if (array_key_exists($file, $GLOBALS['__plugin_raw'] ?? [])) {
                    // 本请求内已 include（如 app.boot 钩子已加载），复用其返回值，避免函数重声明。
                    $raw = $GLOBALS['__plugin_raw'][$file];
                } else {
                    if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
                    $GLOBALS['__plugin_raw'][$file] = $raw = include $file;
                }
            } catch (\Throwable $e) { $disable($id, $file, $e->getMessage()); continue; }
            if (!is_array($raw)) { $disable($id, $file, '插件定义格式无效（应 return 数组）'); continue; }
            $plugin = self::plugin_manifest_validate($raw, $file);
            if (!$plugin) { $disable($id, $file, '插件 manifest 校验失败（缺少 id/name/version 或 ID 与目录不符）'); continue; }

            $id = (string)$plugin['id'];
            $old = $existing[$id] ?? [];
            $code_hash = hash_file('sha256', $file) ?: '';
            $config = plugin_json_decode($old['config_json'] ?? '') ?? [];
            $entries = isset($old['entries_json']) ? plugin_json_decode($old['entries_json']) ?? [] : [];
            foreach (plugin_entry_definitions() as $entry => $def) {
                if (!isset($plugin['hooks'][$def['hook']]) || array_key_exists($entry, $entries)) continue;
                $entries[$entry] = !array_key_exists($entry, (array)$plugin['entries']) || !empty($plugin['entries'][$entry]);
            }
            $enabled = isset($old['enabled']) ? (int)$old['enabled'] : 0;
            app_db_upsert('app_plugins', [
                'id' => $id,
                'name' => (string)$plugin['name'],
                'version' => (string)$plugin['version'],
                'file' => ltrim(str_replace(APP_ROOT, '', $file), '/'),
                'code_hash' => $code_hash,
                'manifest_json' => plugin_json_encode(array_intersect_key($plugin, array_flip(['description', 'author', 'hooks', 'routes', 'admin_tabs', 'assets', 'cron', 'install', 'uninstall']))),
                'config_json' => plugin_json_encode($config),
                'entries_json' => plugin_json_encode($entries),
                'enabled' => $enabled,
                'status' => $enabled ? 'enabled' : 'disabled',
                'disabled_reason' => '',
                'installed_at' => (int)($old['installed_at'] ?? 0) ?: now(),
                'updated_at' => isset($old['updated_at']) && (string)($old['code_hash'] ?? '') === $code_hash ? (int)$old['updated_at'] : now(),
            ], ['id']);
            $synced[$id] = true;
        }
        // 删除已不存在于目录的插件注册项。
        foreach (array_keys($existing) as $id) {
            if (isset($synced[$id])) continue;
            q('DELETE FROM app_plugins WHERE id=?', [$id]);
        }
        plugins(true);
        save_settings_values(['plugin_assets_dirty' => '1']);
        return self::plugin_registry(null, true);
    }

    /**
     * 启动期自动同步（DX 增强）：发现插件目录与注册表漂移时自动重同步 + 重建合并资源。
     * 覆盖三种漂移：①新增插件目录未注册；②已注册插件被删除；③plugin.php 内容变更（sha256 与 DB code_hash 不一致）。
     * 适用场景：开发者直接新增/修改 plugin.php 后无需手动到后台点「同步插件」（新插件仍需到后台启用）。
     * 成本：一次 glob + 对现存插件（通常 <10）各 hash_file 一次，小文件下 <1ms。
     * 安全：仅在 install.lock 存在且 DB 就绪时执行；失败时静默降级不阻断主请求。
     */
    public static function plugin_registry_autosync(): bool
    {
        if (!defined('INSTALL_LOCK_FILE') || !is_file(INSTALL_LOCK_FILE)) return false;
        try {
            $on_disk = [];
            foreach (self::plugin_files() as $file) $on_disk[basename(dirname($file))] = $file;
            $in_db = [];
            foreach (q('SELECT id,code_hash FROM app_plugins')->fetchAll() as $row) $in_db[(string)$row['id']] = (string)($row['code_hash'] ?? '');

            $stale = [] !== array_diff_key($on_disk, $in_db) || [] !== array_diff_key($in_db, $on_disk);
            if (!$stale) {
                foreach ($on_disk as $id => $abs) {
                    if ((hash_file('sha256', $abs) ?: '') !== $in_db[$id]) { $stale = true; break; }
                }
            }
            if (!$stale) return false;
            self::plugin_registry_sync();
            self::plugin_assets_rebuild();
            save_settings_values(['plugin_assets_dirty' => '0']);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    // --- 启用 / 停用 / 卸载 ---
    public static function plugin_set_enabled(string $id, bool $enabled, bool $run_install = false): void
    {
        if (!plugin_id_valid($id)) err('插件不存在');
        $plugin = self::plugin_registry($id)[$id] ?? null;
        if (!$plugin) err('插件不存在');
        if ($enabled) {
            $row = one('SELECT status,disabled_reason FROM app_plugins WHERE id=?', [$id]);
            if ((string)($row['status'] ?? '') === 'error') err('插件存在错误，请先修复后重新同步：' . (string)($row['disabled_reason'] ?? ''));
            plugin_call($plugin, function () use ($plugin, $run_install): void {
                if (($run_install || !plugin_enabled($plugin)) && plugin_callback_exists($plugin['install'] ?? null)) {
                    call_user_func((string)$plugin['install'], $plugin);
                }
            });
        }
        self::plugin_update_row($id, ['enabled' => $enabled ? 1 : 0, 'status' => $enabled ? 'enabled' : 'disabled', 'disabled_reason' => ''], false);
        plugins(true);
        save_settings_values(['plugin_assets_dirty' => '1']);
        self::$registry_cache = [];
    }

    public static function plugin_uninstall(string $id, bool $keep_data = true): void
    {
        if (!plugin_id_valid($id)) err('插件不存在');
        $plugin = self::plugin_registry($id)[$id] ?? null;
        if (!$plugin) err('插件不存在');
        plugin_call($plugin, function () use ($plugin, $id, $keep_data): void {
            $fn = (string)($plugin['uninstall'] ?? '');
            if (!plugin_callback_exists($fn)) return;
            $accepts = (new \ReflectionFunction($fn))->getNumberOfParameters() >= 2;
            if ($keep_data && !$accepts) return;
            if ($accepts) call_user_func($fn, $plugin, $keep_data); else call_user_func($fn, $plugin);
        });
        q('DELETE FROM app_plugins WHERE id=?', [$id]);
        plugins(true);
        plugin_runtime_cache_reset();
        save_settings_values(['plugin_assets_dirty' => '1', 'cache_plugins' => '']);
        self::$registry_cache = [];
    }

    // 插件运行异常时自动停用，避免拖垮全站。
    public static function plugin_disable_after_exception(string $id, \Throwable $e): void
    {
        if (!plugin_id_valid($id)) return;
        try {
            q("UPDATE app_plugins SET enabled=0,status='error',disabled_reason=? WHERE id=?", [cut('运行异常：' . $e->getMessage(), 200), $id]);
            save_settings_values(['cache_plugins' => '', 'plugin_assets_dirty' => '1']);
        } catch (\Throwable) {}
    }

    // --- 资源合并 ---
    public static function plugin_assets_rebuild(): array
    {
        $chunks = ['css' => [], 'js' => []];
        foreach (plugins() as $plugin) {
            if (!plugin_enabled($plugin) || empty($plugin['assets'])) continue;
            foreach (['css', 'js'] as $type) {
                $fn = $plugin['assets'][$type] ?? null;
                if (!is_string($fn)) continue;
                $content = trim((string)plugin_call($plugin, fn(): string => plugin_callback_exists($fn) ? (string)$fn() : ''));
                if ($content === '') continue;
                $chunks[$type][] = '/* ' . (string)$plugin['id'] . " */\n" . $content;
            }
        }
        $manifest = [];
        foreach (['css' => PLUGIN_CSS_FILE, 'js' => PLUGIN_JS_FILE] as $key => $file) {
            $content = implode("\n", $chunks[$key]) . ($chunks[$key] ? "\n" : '');
            self::asset_write($file, $content);
            $manifest[$key] = hash('sha256', $content);
            $manifest[$key . '_size'] = strlen($content);
        }
        save_settings_values([
            'plugin_assets_css_hash' => (string)$manifest['css'],
            'plugin_assets_css_size' => (string)(int)$manifest['css_size'],
            'plugin_assets_js_hash' => (string)$manifest['js'],
            'plugin_assets_js_size' => (string)(int)$manifest['js_size'],
            'plugin_assets_dirty' => '0',
        ]);
        return $manifest;
    }

    private static function asset_write(string $file, string $content): void
    {
        if (!is_dir(dirname($file))) mkdir(dirname($file), 0755, true);
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $content, LOCK_EX) !== false && @rename($tmp, $file)) return;
        @unlink($tmp);
        if (file_put_contents($file, $content, LOCK_EX) === false) throw new \RuntimeException('插件资源文件不可写：' . basename($file));
    }

    // --- 计划任务调度 ---
    public static function cron_run_due_tasks(): int
    {
        $ran = 0;
        $now = now();
        foreach (plugins() as $plugin) {
            if (!plugin_enabled($plugin) || empty($plugin['cron'])) continue;
            plugin_load($plugin); // 先加载插件文件：回调与 interval 函数名均需加载后才能被解析
            foreach ((array)$plugin['cron'] as $name => $task) {
                $callback = (string)($task['callback'] ?? '');
                if (!plugin_callback_exists($callback)) continue;
                $interval = (int)($task['interval'] ?? 0);
                if (is_string($task['interval'] ?? null) && plugin_callback_exists($task['interval'])) {
                    $interval = (int)plugin_call($plugin, fn(): int => (int)call_user_func((string)$task['interval'], $plugin));
                }
                if ($interval < 60) continue;
                $state_key = 'cron_last_' . $plugin['id'] . '_' . $name;
                $last = (int)setting($state_key, '0');
                if ($last > 0 && ($now - $last) < $interval) continue;
                // 简单互斥：先占位再执行，避免并发重复。
                save_settings_values([$state_key => (string)$now]);
                try { plugin_call($plugin, fn() => call_user_func($callback, $plugin, $task)); $ran++; }
                catch (\Throwable $e) { error_log('[Mono cron] ' . $plugin['id'] . '/' . $name . ': ' . $e->getMessage()); }
            }
        }
        return $ran;
    }

    // --- 后台插件页 ---
    public static function admin_plugins_page_html(): string
    {
        $plugins = self::plugin_registry(null, true);

        // 插件配置子页：独立视图（返回链接 + 配置卡片），不再追加在列表底部。
        $view = (string)($_GET['view'] ?? '');
        if ($view !== '' && isset($plugins[$view])) {
            $plugin = $plugins[$view];
            $tabs = $plugin['admin_tabs'] ?? [];
            $tab = $tabs[$view] ?? (count($tabs) === 1 ? reset($tabs) : null);
            if ((bool)($plugin['enabled'] ?? false) && is_string($tab) && $tab !== '') {
                plugin_load($plugin); // 先加载插件，回调函数才存在
                if (plugin_callback_exists($tab)) {
                    return '<div class="card">'
                        . '<div class="btn-row" style="margin-bottom:14px"><a class="btn sm ghost" href="' . h(admin_url(['tab' => 'plugins'])) . '">← 返回插件列表</a></div>'
                        . '<div class="card-title">' . h((string)$plugin['name']) . ' 配置</div>'
                        . '<p style="color:var(--text-muted);font-size:var(--font-size-sm);margin-top:0">' . h((string)($plugin['description'] ?? '')) . '</p>'
                        . (string)call_user_func($tab, $plugin)
                        . '</div>';
                }
            }
        }

        $files = self::plugin_files();
        $dir_ids = array_map(static fn($f): string => basename(dirname($f)), $files);
        $pending = array_diff($dir_ids, array_keys($plugins));

        $html = '<div class="card"><div class="card-title">插件管理</div>'
            . '<p style="color:var(--text-muted);font-size:var(--font-size-sm);margin-top:0">在这里启用或停用插件，为博客添加评论、主题换肤等能力。核心已内置完整的博客功能，插件可按需开启。</p>'
            . '<div class="btn-row">'
            . '<form method="post" style="display:inline">' . form_token() . '<input type="hidden" name="admin_action" value="noop"><button class="btn" type="submit" name="plugin_action" value="sync">同步插件目录</button></form>'
            . '</div>';
        if ($pending) {
            $html .= '<div class="note" style="margin-top:12px">检测到未注册的新插件目录：' . h(implode(', ', $pending)) . '，请点击「同步插件目录」。</div>';
        }
        $html .= '</div>';

        if (!$plugins) return $html . '<div class="card"><p style="color:var(--text-muted);margin:0">尚未安装任何插件。将插件放入 <code>app/plugins/&lt;插件ID&gt;/plugin.php</code> 后点击同步。</p></div>';

        // 已启用优先，其次按名称。
        uasort($plugins, static fn(array $a, array $b): int => ((int)$b['enabled'] <=> (int)$a['enabled']) ?: strcmp((string)$a['name'], (string)$b['name']));
        foreach ($plugins as $plugin) {
            $id = (string)$plugin['id'];
            $enabled = (bool)$plugin['enabled'];
            $reason = (string)($plugin['disabled_reason'] ?? '');
            $status_badge = $enabled ? '<span class="badge" style="background:#dcfce7;color:var(--success)">已启用</span>'
                : ($reason !== '' ? '<span class="badge draft">错误</span>' : '<span class="badge">已停用</span>');
            $html .= '<div class="card" data-slot="admin.plugin.item"><div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap">'
                . '<div style="min-width:0;flex:1"><strong style="font-size:var(--font-size-lg)">' . h((string)$plugin['name']) . '</strong> '
                . '<span style="color:var(--text-subtle);font-size:var(--font-size-xs)">v' . h((string)$plugin['version']) . ' · ' . h($id) . '</span> ' . $status_badge
                . '<div style="color:var(--text-muted);font-size:var(--font-size-sm);margin-top:6px">' . h((string)($plugin['description'] ?? '')) . '</div>'
                . ($reason !== '' ? '<div style="color:var(--danger);font-size:var(--font-size-xs);margin-top:6px">' . h($reason) . '</div>' : '')
                . '<div style="color:var(--text-subtle);font-size:var(--font-size-xs);margin-top:4px">作者：' . h((string)($plugin['author'] ?? '—')) . '</div>'
                . '</div><div class="btn-row" style="align-items:flex-start">';
            // 插件自有后台配置页。
            $tabs = $plugin['admin_tabs'] ?? [];
            $admin_tab = $tabs[$id] ?? (count($tabs) === 1 ? reset($tabs) : null);
            if ($enabled && is_string($admin_tab) && $admin_tab !== '') {
                $html .= '<a class="btn sm" href="' . h(admin_url(['tab' => 'plugins', 'view' => $id])) . '">配置</a>';
            }
            if ($enabled) {
                $html .= self::plugin_action_form($id, 'disable', '停用', 'btn sm ghost');
            } else {
                $html .= self::plugin_action_form($id, 'enable', '启用', 'btn sm');
            }
            $html .= self::plugin_action_form($id, 'uninstall', '卸载', 'btn sm danger', '确定卸载插件 ' . h($id) . ' 吗？');
            $html .= '</div></div></div>';
        }
        return $html;
    }

    private static function plugin_action_form(string $id, string $action, string $label, string $class = 'btn sm ghost', string $confirm = ''): string
    {
        $attrs = $confirm !== '' ? ' data-confirm="' . $confirm . '"' : '';
        if ($action === 'uninstall') {
            // 确认弹窗内的附加勾选项：勾选后把 keep_plugin_data 覆盖为 0（连数据一起删除）。
            $attrs .= ' data-confirm-option="同时删除插件数据（不可恢复）" data-confirm-option-set="keep_plugin_data=0"';
        }
        return '<form method="post" style="display:inline"' . $attrs . '>'
            . form_token() . '<input type="hidden" name="admin_action" value="noop">'
            . '<input type="hidden" name="plugin_action" value="' . h($action) . '">'
            . '<input type="hidden" name="plugin_id" value="' . h($id) . '">'
            . ($action === 'uninstall' ? '<input type="hidden" name="keep_plugin_data" value="1">' : '')
            . '<button type="submit" class="' . h($class) . '">' . h($label) . '</button></form>';
    }

    // 后台插件相关 POST（由 Admin::handle_post 在 tab=plugins 时调用）。
    public static function admin_plugins_handle_post(): void
    {
        $action = (string)($_POST['plugin_action'] ?? '');
        $id = (string)($_POST['plugin_id'] ?? '');
        if ($action === 'sync') {
            self::plugin_registry_sync();
            self::plugin_assets_rebuild();
            save_settings_values(['plugin_sync_pending' => '0', 'plugin_assets_dirty' => '0']);
            set_flash('插件目录已同步');
            go(admin_url(['tab' => 'plugins']));
        }
        if ($action === 'enable') {
            self::plugin_set_enabled($id, true);
            // 插件带配置页时，启用后直接进入其设置页，避免遗漏必要配置。
            $plugin = self::plugin_registry($id)[$id] ?? null;
            $tabs = (array)($plugin['admin_tabs'] ?? []);
            $tab = $tabs[$id] ?? (count($tabs) === 1 ? reset($tabs) : null);
            if (is_string($tab) && $tab !== '') {
                set_flash('插件已启用，请完成或核对下列配置');
                go(admin_url(['tab' => 'plugins', 'view' => $id]));
            }
            set_flash('插件已启用');
            go(admin_url(['tab' => 'plugins']));
        }
        if ($action === 'disable') { self::plugin_set_enabled($id, false); set_flash('插件已停用'); go(admin_url(['tab' => 'plugins'])); }
        if ($action === 'uninstall') {
            $keep = (string)($_POST['keep_plugin_data'] ?? '1') === '1';
            self::plugin_uninstall($id, $keep);
            set_flash('插件已卸载' . ($keep ? '（数据已保留）' : '（数据已删除）'));
            go(admin_url(['tab' => 'plugins']));
        }
        // 插件自有配置保存：委托给插件的 admin_tabs 回调处理 POST。
        $plugin = self::plugin_registry($id)[$id] ?? null;
        if ($plugin && $action === 'save_config') {
            $tab = $plugin['admin_tabs'][$id] ?? null;
            if (is_string($tab) && $tab !== '') {
                plugin_load($plugin); // 先加载插件，回调函数才存在
                if (plugin_callback_exists($tab)) {
                    call_user_func($tab, $plugin);
                }
            }
            go(admin_url(['tab' => 'plugins', 'view' => $id]));
        }
    }
}
