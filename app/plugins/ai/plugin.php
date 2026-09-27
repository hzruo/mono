<?php
if (!defined('APP_ROOT')) exit;

/**
 * AI 助手插件（ai）。
 *
 * 零核心改动，全部通过现有 Hook / 路由 / cron 契约实现：
 * - hook page.before_render：在写文章页的 Markdown 工具栏注入 AI 按钮与弹窗（字符串锚点注入，
 *   锚点缺失时原样返回，不影响其它页面）。
 * - route ai_generate：写文章页 AJAX（管理员），调用 OpenAI 兼容的 chat/completions 接口，
 *   支持续写 / 润色 / 排版 / 生成标签 / 自定义指令。
 * - admin_tabs ai：配置 provider、Base URL、API Key、模型（自动拉取 /models 列表供选择、
 *   可测试连通性），以及评论自动审核开关。
 * - cron review：按定时任务频率巡检待审核评论（任务最小间隔 60 秒；评论插件开启「先审后显」时），AI 判断
 *   通过 → status=1 公开展示；拒绝 → status=-1（保持不可见，后台仍可人工通过）。
 *   审核结论记录在 plugin_ai_reviews，供后台追溯。
 *
 * 安全：所有 HTTP 调用仅在服务端进行（API Key 不出浏览器）；路由全部 need_admin + CSRF；
 * 回调内自行 try/catch，避免异常导致插件被核心自动停用。
 */

// 归一化配置（旧配置缺字段不报错）。
function ai_config(): array
{
    $raw = plugin_config('ai', []);
    $models = $raw['models'] ?? [];
    if (!is_array($models)) $models = [];
    return [
        'provider' => (string)($raw['provider'] ?? 'custom'),
        'baseurl' => rtrim(trim((string)($raw['baseurl'] ?? '')), '/'),
        'key' => (string)($raw['key'] ?? ''),
        'model' => trim((string)($raw['model'] ?? '')),
        'models' => array_values(array_filter(array_map('strval', $models))),
        'temperature' => min(2.0, max(0.0, (float)($raw['temperature'] ?? 0.7))),
        'review_enabled' => (int)($raw['review_enabled'] ?? 0) === 1,
        'review_limit' => min(20, max(1, (int)($raw['review_limit'] ?? 5))),
        'review_prompt' => (string)($raw['review_prompt'] ?? ''),
    ];
}

// 就绪判定：Base URL 与模型是调用下限（本地 Ollama 等可不填 Key）。
function ai_ready(array $cfg): bool
{
    return $cfg['baseurl'] !== '' && $cfg['model'] !== '';
}

// 常见 OpenAI 兼容服务预设（下拉选项 + 自动填 Base URL）。
function ai_providers(): array
{
    return [
        'custom' => ['label' => '自定义 / 其它兼容服务', 'baseurl' => ''],
        'openai' => ['label' => 'OpenAI', 'baseurl' => 'https://api.openai.com/v1'],
        'deepseek' => ['label' => 'DeepSeek', 'baseurl' => 'https://api.deepseek.com/v1'],
        'moonshot' => ['label' => 'Moonshot（Kimi）', 'baseurl' => 'https://api.moonshot.cn/v1'],
        'zhipu' => ['label' => '智谱 GLM', 'baseurl' => 'https://open.bigmodel.cn/api/paas/v4'],
        'dashscope' => ['label' => '通义千问（DashScope 兼容模式）', 'baseurl' => 'https://dashscope.aliyuncs.com/compatible-mode/v1'],
        'siliconflow' => ['label' => 'SiliconFlow 硅基流动', 'baseurl' => 'https://api.siliconflow.cn/v1'],
        'ollama' => ['label' => 'Ollama（本地部署）', 'baseurl' => 'http://127.0.0.1:11434/v1'],
    ];
}

function ai_install(array $plugin): void
{
    $t = app_db_types();
    app_db_create_table('plugin_ai_reviews', "id {$t['id']},comment_id {$t['uint']} NOT NULL,approved {$t['uint']} NOT NULL DEFAULT 0,reason {$t['string']},model {$t['string']},created_at {$t['uint']} NOT NULL,UNIQUE (comment_id)");
}

function ai_uninstall(array $plugin, bool $keep_data = true): void
{
    if (!$keep_data) app_db_drop_table('plugin_ai_reviews');
}

// --- HTTP 层：OpenAI 兼容接口调用（curl 优先，stream 兜底；零第三方依赖）---
function ai_http(array $cfg, string $path, ?array $payload, int $timeout): array
{
    $url = $cfg['baseurl'] . '/' . ltrim($path, '/');
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($cfg['key'] !== '') $headers[] = 'Authorization: Bearer ' . $cfg['key'];
    $body = $payload === null ? null : (json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}');

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = (string)curl_error($ch);
        curl_close($ch);
        if ($resp === false || $errno !== 0) return [false, 0, '网络请求失败：' . ($error !== '' ? $error : '未知错误')];
        return [true, $status, (string)$resp];
    }

    // stream 兜底（host 环境无 curl 扩展时）。
    $ctx = stream_context_create(['http' => [
        'method' => $body === null ? 'GET' : 'POST',
        'header' => implode("\r\n", $headers),
        'content' => (string)$body,
        'timeout' => $timeout,
        'ignore_errors' => true,
        'follow_location' => 1,
    ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) return [false, 0, '网络请求失败：无法连接 ' . $cfg['baseurl']];
    $status = 0;
    foreach ((array)($http_response_header ?? []) as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$line, $m)) $status = (int)$m[1];
    }
    return [true, $status, (string)$resp];
}

// 拉取模型列表（GET /models）。
function ai_fetch_models(array $cfg): array
{
    if ($cfg['baseurl'] === '') return [false, [], '请先填写 Base URL'];
    [$ok, $status, $body] = ai_http($cfg, 'models', null, 15);
    if (!$ok) return [false, [], (string)$body];
    $data = json_decode((string)$body, true);
    $list = [];
    foreach ((array)($data['data'] ?? []) as $m) {
        if (is_array($m) && isset($m['id']) && (string)$m['id'] !== '') $list[] = (string)$m['id'];
    }
    if (!$list) {
        $msg = (string)($data['error']['message'] ?? '');
        return [false, [], '未解析到模型列表（HTTP ' . $status . '）' . ($msg !== '' ? '：' . $msg : '')];
    }
    $list = array_values(array_unique($list));
    sort($list);
    return [true, $list, ''];
}

// 对话调用（POST {baseurl}/chat/completions），返回 [ok, 文本|错误信息]。
function ai_chat(array $cfg, array $messages, ?float $temperature = null, int $timeout = 90): array
{
    $payload = [
        'model' => $cfg['model'],
        'messages' => $messages,
        'temperature' => $temperature ?? $cfg['temperature'],
        'stream' => false,
    ];
    [$ok, $status, $body] = ai_http($cfg, 'chat/completions', $payload, $timeout);
    if (!$ok) return [false, (string)$body];
    $data = json_decode((string)$body, true);
    if ($status >= 400) {
        $msg = (string)($data['error']['message'] ?? ('HTTP ' . $status));
        if ($status === 401) $msg = '鉴权失败（' . $msg . '），请检查 API Key';
        return [false, $msg];
    }
    $text = (string)($data['choices'][0]['message']['content'] ?? '');
    return trim($text) !== '' ? [true, trim($text)] : [false, '模型返回内容为空'];
}

// 连通性测试：用极短对话验证 Base URL / Key / 模型三者可用。
function ai_test_connection(array $cfg): array
{
    if ($cfg['baseurl'] === '') return [false, '请先填写 Base URL'];
    if ($cfg['model'] === '') return [false, '请先选择或填写模型'];
    [$ok, $text] = ai_chat($cfg, [['role' => 'user', 'content' => '请只回复两个字：正常']], 0, 30);
    if ($ok) return [true, '连通正常（模型回复：' . cut($text, 40) . '）'];
    return [false, $text];
}

// --- 写作辅助：按动作组装提示词 ---
function ai_build_messages(string $action, string $content, string $title, string $instruction): array
{
    $sys = '你是一位专业的中文博客写作助手。保持用户原文的语言与写作风格，除非用户另行要求。';
    switch ($action) {
        case 'continue':
            $user = "请根据下面的文章（标题与已有正文），自然地续写接下来的内容，保持相同的语气、风格与 Markdown 格式。"
                . "只输出续写的内容本身，不要重复已有内容，不要输出任何解释。\n\n标题：" . $title . "\n\n已有正文：\n" . $content;
            break;
        case 'polish':
            $user = "请对下面的文字进行润色：提升用词、语法与表达流畅度，保持原意、信息量与 Markdown 结构不变。"
                . "只输出润色后的文字，不要输出任何解释。\n\n" . $content;
            break;
        case 'format':
            $user = "请对下面的 Markdown 文章进行排版整理：规范标题层级、段落、列表、引用、代码块与中英文标点，"
                . "不要增删事实内容，不要输出任何解释，只输出整理后的完整 Markdown 正文。\n\n标题：" . $title . "\n\n正文：\n" . $content;
            break;
        case 'tags':
            $user = "请根据下面的文章内容，提炼 3 到 6 个精准的中文标签。只输出标签本身，用英文逗号分隔，"
                . "不要带 # 号、编号或其它任何文字。\n\n标题：" . $title . "\n\n正文：\n" . $content;
            break;
        default: // custom
            $user = $instruction !== '' ? $instruction : '请优化下面的文章内容。';
            if ($title !== '') $user = '文章标题：' . $title . "\n\n" . $user;
            if ($content !== '') $user .= "\n\n待处理内容：\n" . $content;
    }
    return [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $user]];
}

// 写文章页 AJAX：生成内容。返回 {ok:1, text} 或 {ok:0, message}。
function ai_generate(array $plugin): void
{
    need_admin();
    require_post();
    @set_time_limit(150);
    $action = (string)($_POST['ai_action'] ?? '');
    if (!in_array($action, ['continue', 'polish', 'format', 'tags', 'custom'], true)) {
        json_response(['ok' => 0, 'message' => '不支持的操作']);
    }
    $cfg = ai_config();
    if (!ai_ready($cfg)) json_response(['ok' => 0, 'message' => 'AI 尚未配置完成，请到「后台 → 插件 → AI 助手」填写 Base URL 并选择模型']);
    $content = (string)($_POST['content'] ?? '');
    if (mb_strlen($content) > 48000) $content = mb_substr($content, 0, 48000); // 超长请求保护
    $title = post('title', 200);
    $instruction = post('instruction', 300);
    if (trim($content) === '' && $action !== 'custom') json_response(['ok' => 0, 'message' => '正文为空，请先写一些内容']);
    $temperature = $action === 'tags' ? 0.3 : $cfg['temperature'];
    [$ok, $text] = ai_chat($cfg, ai_build_messages($action, $content, $title, $instruction), $temperature, 150);
    if (!$ok) json_response(['ok' => 0, 'message' => (string)$text]);
    json_response(['ok' => 1, 'action' => $action, 'text' => $text]);
}

// --- 写文章页注入：工具栏 AI 按钮 + 弹窗 ---
function ai_write_inject(string $value, array $ctx): string
{
    $title = (string)($ctx['title'] ?? '');
    if ($title !== '写文章' && $title !== '编辑文章') return $value;
    if (!str_contains($value, '<span class="md-spacer"></span>') || !str_contains($value, '<div class="btn-row write-actions">')) return $value;

    $btn = '<button type="button" class="md-btn ai-open" data-ai-open="1" title="AI 写作助手">'
        . '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9z"/></svg>AI</button>';
    $value = str_replace('<span class="md-spacer"></span>', $btn . '<span class="md-spacer"></span>', $value);
    return str_replace('<div class="btn-row write-actions">', ai_modal_html(ai_config()) . '<div class="btn-row write-actions">', $value);
}

function ai_modal_html(array $cfg): string
{
    $ready = ai_ready($cfg);
    $admin = admin_url(['tab' => 'plugins', 'view' => 'ai']);
    $html = '<div class="ai-modal" id="ai-modal" hidden data-url="' . h(route_url('ai_generate')) . '" data-csrf="' . h(csrf_token()) . '" data-ready="' . ($ready ? '1' : '0') . '">'
        . '<div class="ai-mask" data-ai-close="1"></div>'
        . '<div class="ai-panel" role="dialog" aria-modal="true" aria-label="AI 写作助手">'
        . '<div class="ai-head"><strong>AI 写作助手</strong><button type="button" class="ai-x" data-ai-close="1" title="关闭" aria-label="关闭">×</button></div>';
    if (!$ready) {
        return $html . '<div class="ai-tip">尚未配置 AI 接口。请先到 <a href="' . h($admin) . '">后台 → 插件 → AI 助手</a> 填写 Base URL、API Key 并选择模型。</div></div></div>';
    }
    return $html
        . '<div class="ai-tabs">'
        . '<button type="button" class="ai-tab" data-ai-action="continue" title="根据已有内容继续写作">续写</button>'
        . '<button type="button" class="ai-tab" data-ai-action="polish" title="润色选中文字（未选中时按全文处理）">润色</button>'
        . '<button type="button" class="ai-tab" data-ai-action="format" title="整理全文 Markdown 排版">排版</button>'
        . '<button type="button" class="ai-tab" data-ai-action="tags" title="根据全文生成标签">生成标签</button>'
        . '<button type="button" class="ai-tab" data-ai-action="custom" title="按自定义指令处理选中内容或全文">自定义</button>'
        . '</div>'
        . '<div class="ai-custom" hidden><input type="text" id="ai-instruction" placeholder="自定义指令，如：把这段扩写成三段" maxlength="300"><button type="button" class="btn sm" id="ai-custom-run">执行</button></div>'
        . '<div class="ai-hint" id="ai-hint">提示：先在正文中选中文字再点「润色 / 自定义」，可只处理选中部分。</div>'
        . '<div class="ai-loading" hidden><span class="ai-spin" aria-hidden="true"></span>生成中，请稍候…</div>'
        . '<div class="ai-error" hidden></div>'
        . '<div class="ai-result" hidden><textarea class="ai-text" spellcheck="false"></textarea>'
        . '<div class="ai-apply">'
        . '<button type="button" class="btn sm" data-ai-apply="replace_selection" hidden>替换选中</button>'
        . '<button type="button" class="btn sm" data-ai-apply="insert" hidden>插入光标处</button>'
        . '<button type="button" class="btn sm" data-ai-apply="append" hidden>追加到文末</button>'
        . '<button type="button" class="btn sm" data-ai-apply="replace_all" hidden>替换全文</button>'
        . '<button type="button" class="btn sm" data-ai-apply="tags" hidden>填入标签框</button>'
        . '<button type="button" class="btn sm ghost" data-ai-apply="copy">复制</button>'
        . '<button type="button" class="btn sm ghost" data-ai-close="1">放弃</button>'
        . '</div></div></div></div>';
}

// --- 评论自动审核（cron 每分钟巡检）---
function ai_review_context(array $cfg): array
{
    if (!$cfg['review_enabled']) return [false, '自动审核未启用'];
    if (!ai_ready($cfg)) return [false, 'AI 未配置完成'];
    if ((int)val('SELECT enabled FROM app_plugins WHERE id=?', ['comment']) !== 1) return [false, '评论插件未启用'];
    if (setting('allow_comment', '1') !== '1') return [false, '站点评论已关闭'];
    if ((int)(plugin_config('comment', [])['moderate'] ?? 0) !== 1) return [false, '评论未开启「先审后显」'];
    try {
        q('SELECT id FROM plugin_comment_comments LIMIT 1');
    } catch (\Throwable) {
        return [false, '评论数据表不存在'];
    }
    return [true, ''];
}

// 解析模型输出的审核结论：优先 JSON，失败时正则兜底。返回 [approved(bool), reason] 或 null。
function ai_parse_verdict(string $text): ?array
{
    $clean = trim($text);
    $start = strpos($clean, '{');
    $end = strrpos($clean, '}');
    $data = null;
    if ($start !== false && $end !== false && $end > $start) {
        $data = json_decode(substr($clean, $start, $end - $start + 1), true);
    }
    if (!is_array($data) || !array_key_exists('approved', $data)) {
        if (preg_match('/"?approved"?\s*[:：]\s*(true|false)/i', $clean, $m)) {
            $data = ['approved' => strtolower($m[1]) === 'true', 'reason' => ''];
        } else {
            return null;
        }
    }
    $raw = $data['approved'];
    $approved = $raw === true || $raw === 1 || $raw === '1' || (is_string($raw) && strtolower($raw) === 'true');
    return ['approved' => $approved, 'reason' => trim((string)($data['reason'] ?? ''))];
}

// 单条评论送审。成功返回 [approved, reason]，调用失败返回 null（保持待审，不误删）。
function ai_review_one(array $cfg, array $comment): ?array
{
    $post = row('app_posts', 'id', (int)$comment['post_id']);
    $title = (string)($post['title'] ?? '（文章已删除）');
    $default = '你是一个博客评论审核助手。请判断评论是否适合公开展示：拒绝垃圾广告、谩骂攻击、涉黄涉政涉暴、纯链接或无意义灌水；'
        . '正常的讨论、提问、建议与友好互动应通过。拿不准时倾向拒绝。';
    // 输出格式约束由系统统一追加，用户自定义提示词只需描述审核标准。
    $sys = (trim($cfg['review_prompt']) !== '' ? trim($cfg['review_prompt']) : $default)
        . ' 输出要求：只输出一个 JSON 对象，形如 {"approved": true, "reason": "一句话中文理由"}，不要输出任何其它内容；approved 为 true 表示通过展示，false 表示拒绝。';
    $user = '文章标题：' . $title . "\n评论作者：" . (string)$comment['author'] . "\n评论内容：" . (string)$comment['content']
        . "\n\n请只输出如下 JSON，不要输出其它任何内容：\n{\"approved\": true, \"reason\": \"一句话中文理由\"}\n"
        . '其中 approved 为 true 表示通过展示，false 表示拒绝。';
    [$ok, $text] = ai_chat($cfg, [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $user]], 0.2, 60);
    if (!$ok) return null;
    return ai_parse_verdict((string)$text);
}

// 巡检待审评论：通过 → status=1；拒绝 → status=-1（保持不可见，后台可人工复核）。
function ai_review_pending(int $limit): array
{
    $stats = ['checked' => 0, 'approved' => 0, 'rejected' => 0, 'failed' => 0, 'message' => ''];
    $cfg = ai_config();
    [$ok, $why] = ai_review_context($cfg);
    if (!$ok) { $stats['message'] = $why; return $stats; }
    $rows = all('SELECT * FROM plugin_comment_comments WHERE status=0 ORDER BY id ASC LIMIT ' . max(1, min(20, $limit)));
    foreach ($rows as $c) {
        $stats['checked']++;
        try {
            $verdict = ai_review_one($cfg, $c);
            if ($verdict === null) { $stats['failed']++; continue; }
            app_db_upsert('plugin_ai_reviews', [
                'comment_id' => (int)$c['id'],
                'approved' => $verdict['approved'] ? 1 : 0,
                'reason' => cut($verdict['reason'], 200),
                'model' => $cfg['model'],
                'created_at' => now(),
            ], ['comment_id']);
            q('UPDATE plugin_comment_comments SET status=? WHERE id=?', [$verdict['approved'] ? 1 : -1, (int)$c['id']]);
            $verdict['approved'] ? $stats['approved']++ : $stats['rejected']++;
        } catch (\Throwable $e) {
            $stats['failed']++;
            error_log('[Mono ai] review comment#' . (int)$c['id'] . ': ' . $e->getMessage());
        }
    }
    return $stats;
}

// cron 回调：任何异常只记录日志，绝不抛出（否则核心会停用整个插件）。
function ai_cron_review(array $plugin, array $task): void
{
    try {
        $cfg = ai_config();
        if (!$cfg['review_enabled'] || !ai_ready($cfg)) return;
        ai_review_pending($cfg['review_limit']);
    } catch (\Throwable $e) {
        error_log('[Mono ai] cron review: ' . $e->getMessage());
    }
}

// --- 后台配置页 ---
function ai_admin(array $plugin): string
{
    if (is_post_request()) {
        $action = (string)($_POST['ai_action'] ?? '');
        $back = admin_url(['tab' => 'plugins', 'view' => 'ai']);
        $old = ai_config();

        if ($action === 'save_model' || $action === 'refresh_models' || $action === 'test') {
            @set_time_limit(90);
            $baseurl = rtrim(trim((string)($_POST['baseurl'] ?? '')), '/');
            $key_raw = (string)($_POST['key'] ?? '');
            $key = trim($key_raw) !== '' ? trim($key_raw) : $old['key']; // 留空表示保持
            $model_manual = trim((string)($_POST['model_manual'] ?? ''));
            $model = $model_manual !== '' ? $model_manual : trim((string)($_POST['model'] ?? ''));
            $save = [
                'provider' => (string)($_POST['provider'] ?? 'custom'),
                'baseurl' => $baseurl,
                'key' => $key,
                'model' => $model,
                'models' => $old['models'],
                'temperature' => min(2.0, max(0.0, (float)($_POST['temperature'] ?? 0.7))),
                'review_enabled' => $old['review_enabled'] ? 1 : 0,
                'review_limit' => $old['review_limit'],
                'review_prompt' => $old['review_prompt'],
            ];
            $msg = '设置已保存';
            $flash_type = 'ok';
            // 保存时若 Base URL / Key 有变或从未取过模型，自动拉取模型列表。
            $need_fetch = $action === 'refresh_models'
                || ($action === 'save_model' && $baseurl !== '' && ($baseurl !== $old['baseurl'] || $key !== $old['key'] || !$old['models']));
            if ($need_fetch) {
                [$mok, $list, $merr] = ai_fetch_models(ai_config_rows($save));
                if ($mok) {
                    $save['models'] = $list;
                    if ($save['model'] === '' || !in_array($save['model'], $list, true)) $save['model'] = $list[0];
                    $msg = '设置已保存，已获取 ' . count($list) . ' 个模型';
                } else {
                    $msg = '设置已保存，但自动获取模型失败：' . $merr;
                    $flash_type = 'error';
                }
            }
            plugin_save_config('ai', ai_config_rows($save));
            if ($action === 'test') {
                [$tok, $tmsg] = ai_test_connection(ai_config_rows($save));
                $msg = '设置已保存；' . ($tok ? '连通测试通过：' : '连通测试失败：') . $tmsg;
                $flash_type = $tok ? 'ok' : 'error';
            }
            set_flash($msg, $flash_type);
            go($back);
        }

        if ($action === 'save_review') {
            plugin_save_config('ai', ai_config_rows(array_merge([
                'provider' => $old['provider'],
                'baseurl' => $old['baseurl'],
                'key' => $old['key'],
                'model' => $old['model'],
                'models' => $old['models'],
                'temperature' => $old['temperature'],
            ], [
                'review_enabled' => (int)($_POST['review_enabled'] ?? 0) === 1 ? 1 : 0,
                'review_limit' => min(20, max(1, (int)($_POST['review_limit'] ?? 5))),
                'review_prompt' => trim((string)($_POST['review_prompt'] ?? '')),
            ]), true));
            set_flash('自动审核设置已保存');
            go($back);
        }

        if ($action === 'review_now') {
            @set_time_limit(300);
            // 先保存当前表单里的审核设置，再按新设置执行一轮（与「测试连通性」先保存再执行的约定一致）。
            plugin_save_config('ai', ai_config_rows(array_merge($old, [
                'review_enabled' => (int)($_POST['review_enabled'] ?? 0) === 1 ? 1 : 0,
                'review_limit' => min(20, max(1, (int)($_POST['review_limit'] ?? 5))),
                'review_prompt' => trim((string)($_POST['review_prompt'] ?? '')),
            ])));
            $stats = ai_review_pending(ai_config()['review_limit']);
            if ($stats['message'] !== '') {
                set_flash('设置已保存；未执行：' . $stats['message'], 'error');
            } else {
                set_flash('设置已保存；审核完成：检查 ' . $stats['checked'] . ' 条，通过 ' . $stats['approved'] . ' 条，拒绝 ' . $stats['rejected'] . ' 条，失败 ' . $stats['failed'] . ' 条');
            }
            go($back);
        }
    }

    $cfg = ai_config();
    $providers = ai_providers();

    // 模型服务设置（单「保存」+ 单「测试连通性」；模型列表保存时自动获取，「刷新列表」按需手动拉取）。
    $provider_html = '<label class="grid">' . form_field_caption('服务商', '选择预设可自动填充 Base URL，选「自定义」手动填写')
        . '<select name="provider" id="ai-provider">';
    foreach ($providers as $pk => $p) {
        $provider_html .= '<option value="' . h($pk) . '" data-baseurl="' . h($p['baseurl']) . '"'
            . ($pk === $cfg['provider'] ? ' selected' : '') . '>' . h($p['label']) . '</option>';
    }
    $provider_html .= '</select></label>';
    $model_opts = ['' => '— 请选择 —'];
    $has_current = in_array($cfg['model'], $cfg['models'], true);
    if ($cfg['model'] !== '' && !$has_current) $model_opts[$cfg['model']] = $cfg['model'] . '（当前）';
    foreach ($cfg['models'] as $m) $model_opts[$m] = $m;

    // 模型下拉 + 「刷新列表」组合控件（刷新按钮用当前表单值重新拉取 /models 并保存）。
    $model_select = '<select name="model" style="flex:1;min-width:0">';
    foreach ($model_opts as $mv => $mt) {
        $model_select .= '<option value="' . h((string)$mv) . '"' . ((string)$mv === $cfg['model'] ? ' selected' : '') . '>' . h((string)$mt) . '</option>';
    }
    $model_select .= '</select>';
    $model_field = '<div style="margin-bottom:16px">'
        . form_field_caption('模型', count($cfg['models']) > 0 ? '下拉来自自动获取的模型列表' : '保存 Base URL 与 Key 时会自动获取')
        . '<div style="display:flex;gap:6px">' . $model_select
        . '<button class="btn sm ghost" type="submit" name="ai_action" value="refresh_models" formnovalidate title="用当前表单里的 Base URL 与 Key 重新拉取模型列表">刷新列表</button>'
        . '</div></div>';

    $html = '<form method="post" style="margin-bottom:20px">' . form_token()
        . '<input type="hidden" name="admin_action" value="noop">'
        . '<div style="font-weight:600;margin-bottom:6px">模型服务</div>'
        . '<div class="form-grid-2">'
        . $provider_html
        . input('Base URL', 'baseurl', $cfg['baseurl'], 'text', false, 'OpenAI 兼容接口根地址，如 https://api.openai.com/v1')
        . '</div>'
        . input('API Key', 'key', '', 'password', false, $cfg['key'] !== '' ? '已保存（' . h(ai_mask_key($cfg['key'])) . '），留空保持不变' : '部分本地服务（如 Ollama）可留空')
        . '<div class="form-grid-2">'
        . $model_field
        . input('自定义模型名', 'model_manual', '', 'text', false, '填写后将覆盖左侧下拉选择')
        . '</div>'
        . input('温度（0-2）', 'temperature', (string)$cfg['temperature'], 'number', false, '越小越稳定，写作建议 0.6-0.9')
        . '<div class="btn-row">'
        . '<button class="btn" type="submit" name="ai_action" value="save_model">保存设置</button>'
        . '<button class="btn ghost" type="submit" name="ai_action" value="test">测试连通性</button>'
        . '</div>'
        . '<p style="color:var(--text-muted);font-size:var(--font-size-sm);margin:10px 0 0">保存时若 Base URL / Key 有变更或尚无模型列表，会自动请求一次模型列表；「测试连通性」会先保存当前表单，再向所选模型发一条极短对话验证。</p>'
        . '</form>';

    // 评论自动审核设置（保存与手动执行合并为一张表单；自动化说明见下方 note）。
    [$ctx_ok, $ctx_why] = ai_review_context($cfg);
    $html .= '<form method="post" style="margin-bottom:20px">' . form_token()
        . '<input type="hidden" name="admin_action" value="noop">'
        . '<div style="font-weight:600;margin-bottom:6px">评论自动审核</div>'
        . '<p style="color:var(--text-muted);font-size:var(--font-size-sm);margin-top:0">'
        . '在评论插件开启「先审后显」后，待审评论会被送 AI 判断：通过的评论公开展示；被拒绝的保持不可见，仍可在后台「评论」标签中人工通过；调用失败或返回无法解析时该评论保持待审，不会误删。'
        . ($ctx_ok ? '' : ' <span style="color:var(--warning)">当前状态：' . h($ctx_why) . '，自动审核暂不会触发。</span>')
        . '</p>'
        . checkbox('启用评论自动审核', 'review_enabled', $cfg['review_enabled'])
        . input('每轮最多处理', 'review_limit', (string)$cfg['review_limit'], 'number', false, '每次执行最多处理的条数（1-20）；执行频率由定时任务决定，任务最短 60 秒一轮')
        . textarea('自定义审核提示词', 'review_prompt', $cfg['review_prompt'], false, '留空使用内置默认提示词。只需描述审核标准与尺度，JSON 输出格式由系统自动附加，无需在此约定', 'rows="3"')
        . '<div class="btn-row">'
        . '<button class="btn" type="submit" name="ai_action" value="save_review">保存审核设置</button>'
        . '<button class="btn ghost" type="submit" name="ai_action" value="review_now">立即审核待审评论</button>'
        . '</div>'
        . '<p style="color:var(--text-muted);font-size:var(--font-size-sm);margin:10px 0 0">「立即审核待审评论」会先保存当前设置，再立即执行一轮（处理条数同上），适合没有配置定时任务的站点。</p>'
        . '</form>';

    // 自动化说明：解释定时任务入口的含义、执行频率机制与两种配置方式（HTTP 地址含访问令牌）。
    $cron_cli = cron_cli_command();
    $cron_url = cron_secret_url();
    $html .= '<div class="note">'
        . '<strong>如何让审核全自动？</strong>自动审核不依赖页面访问，而由服务器上的「定时任务（cron）」驱动：让服务器按固定频率（建议每分钟一次）调用一次任务入口即可（设置一次，长期有效）。该入口是所有插件定时任务的统一入口，配置一次即可同时满足其它插件的自动任务；入口很轻量，是否真正执行由任务自身的最小间隔决定（审核任务为 60 秒），调用更频繁也不会重复审核。<br>'
        . '方式一（SSH 执行 <code>crontab -e</code>，添加一行；本地命令无需令牌）：<code>* * * * * ' . h($cron_cli) . '</code><br>'
        . '方式二（宝塔 / 1Panel 等面板添加「每分钟」的计划任务，或任何能定时发请求的服务；注意：下方地址已自动附带安全令牌，必须整体复制使用）：<code>* * * * * curl -s "' . h($cron_url) . '" &gt;/dev/null</code><br>'
        . '安全说明：HTTP 方式调用必须携带令牌（缺失或错误会返回 403）；令牌会随本页地址自动生成并展示，此前配置过旧地址的请替换为最新地址。没有条件配置定时任务时，用上面的「立即审核待审评论」手动执行完全等效。</div>';

    // 审核记录（分页，20 条/页）。
    $page = current_page();
    $per = 20;
    $total_reviews = 0;
    try {
        $total_reviews = (int)val('SELECT COUNT(*) FROM plugin_ai_reviews');
        $reviews = all('SELECT r.*, c.author, c.content FROM plugin_ai_reviews r LEFT JOIN plugin_comment_comments c ON c.id=r.comment_id ORDER BY r.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
    } catch (\Throwable) {
        $reviews = [];
    }
    if ($reviews) {
        $html .= '<div style="font-weight:600;margin:6px 0 8px">审核记录</div>';
        $html .= '<table class="list"><thead><tr><th>评论</th><th>结论</th><th>理由</th><th>时间</th></tr></thead><tbody>';
        foreach ($reviews as $r) {
            $html .= '<tr><td style="max-width:300px;color:var(--text-muted)">' . h(cut((string)($r['author'] ?? '—') . '：' . (string)($r['content'] ?? '（评论已删除）'), 46)) . '</td>'
                . '<td>' . ((int)$r['approved'] === 1 ? '<span class="badge">通过</span>' : '<span class="badge draft">拒绝</span>') . '</td>'
                . '<td style="max-width:260px;color:var(--text-muted)">' . h((string)($r['reason'] ?? '')) . '</td>'
                . '<td style="color:var(--text-subtle)">' . h(date('m-d H:i', (int)$r['created_at'])) . '</td></tr>';
        }
        $html .= '</tbody></table>';
        $html .= paginate($total_reviews, $page, $per, fn(int $p): string => admin_url(['tab' => 'plugins', 'view' => 'ai', 'page' => $p]));
    }
    return $html;
}

// 配置数组规整（保存前统一走一遍，保证键完整、类型正确）。
function ai_config_rows(array $cfg): array
{
    $models = $cfg['models'] ?? [];
    if (!is_array($models)) $models = [];
    $out = [
        'provider' => (string)($cfg['provider'] ?? 'custom'),
        'baseurl' => rtrim(trim((string)($cfg['baseurl'] ?? '')), '/'),
        'key' => (string)($cfg['key'] ?? ''),
        'model' => trim((string)($cfg['model'] ?? '')),
        'models' => array_values(array_filter(array_map('strval', $models))),
        'temperature' => min(2.0, max(0.0, (float)($cfg['temperature'] ?? 0.7))),
        'review_enabled' => (int)($cfg['review_enabled'] ?? 0) === 1 ? 1 : 0,
        'review_limit' => min(20, max(1, (int)($cfg['review_limit'] ?? 5))),
        'review_prompt' => (string)($cfg['review_prompt'] ?? ''),
    ];
    return $out;
}

// Key 掩码展示：仅保留头尾各 3 个字符。
function ai_mask_key(string $key): string
{
    $len = strlen($key);
    if ($len <= 6) return str_repeat('•', max(1, $len));
    return substr($key, 0, 3) . '•••' . substr($key, -3);
}

function ai_css(): string
{
    return <<<'CSS'
.ai-open{display:inline-flex;align-items:center;gap:4px}
.ai-open svg{display:block}
.ai-modal{position:fixed;inset:0;z-index:120;display:flex;align-items:center;justify-content:center;padding:16px}
.ai-modal[hidden]{display:none}
.ai-mask{position:absolute;inset:0;background:rgba(15,15,15,.45)}
.ai-panel{position:relative;width:min(660px,100%);max-height:min(86vh,760px);overflow:auto;overscroll-behavior:contain;background:var(--card);color:var(--card-foreground);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-md);padding:16px}
.ai-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.ai-head strong{font-size:var(--font-size-lg)}
.ai-x{border:0;background:transparent;font-size:20px;line-height:1;color:var(--text-subtle);cursor:pointer;padding:2px 6px;border-radius:6px}
.ai-x:hover{background:var(--accent);color:var(--accent-foreground)}
.ai-tabs{display:flex;flex-wrap:wrap;gap:8px}
.ai-tab{border:1px solid var(--border);background:var(--background);color:var(--foreground);border-radius:999px;padding:6px 16px;font-size:var(--font-size-sm);cursor:pointer}
.ai-tab:hover{border-color:var(--primary);color:var(--primary)}
.ai-tab.busy{opacity:.45;pointer-events:none}
.ai-custom{display:flex;gap:8px;margin-top:10px}
.ai-custom input{flex:1;min-width:0;height:36px;padding:0 12px;border:1px solid var(--input);border-radius:calc(var(--radius) - 4px);background:var(--background);color:var(--foreground)}
.ai-hint{color:var(--text-muted);font-size:var(--font-size-xs);margin-top:10px}
.ai-tip{margin-top:6px;padding:10px 12px;border:1px solid color-mix(in oklab,var(--warning) 32%,transparent);background:color-mix(in oklab,var(--warning) 12%,transparent);color:var(--warning);border-radius:6px;font-size:var(--font-size-sm)}
.ai-loading{display:flex;align-items:center;gap:8px;color:var(--text-muted);font-size:var(--font-size-sm);margin-top:12px}
.ai-spin{width:14px;height:14px;border:2px solid var(--border);border-top-color:var(--primary);border-radius:50%;animation:ai-spin .8s linear infinite;flex:0 0 auto}
@keyframes ai-spin{to{transform:rotate(360deg)}}
.ai-error{margin-top:12px;padding:10px 12px;border:1px solid color-mix(in oklab,var(--danger) 35%,transparent);background:color-mix(in oklab,var(--danger) 10%,transparent);color:var(--danger);border-radius:6px;font-size:var(--font-size-sm);white-space:pre-wrap;word-break:break-word}
.ai-result{margin-top:12px}
.ai-text{display:block;width:100%;min-height:180px;padding:10px 12px;border:1px solid var(--input);border-radius:calc(var(--radius) - 4px);background:var(--background);color:var(--foreground);font-size:var(--font-size-sm);line-height:1.7;resize:vertical}
.ai-text:focus{outline:none;border-color:var(--ring);box-shadow:0 0 0 3px color-mix(in oklab,var(--ring) 25%,transparent)}
.ai-apply{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
@media (max-width:640px){.ai-panel{padding:12px}.ai-text{min-height:140px}}
CSS;
}

function ai_js(): string
{
    return <<<'JS'
(function () {
  'use strict';
  var ta, tagsInput, titleInput;
  var sel = { start: 0, end: 0, text: '' };
  var pending = { action: '', source: '', caret: 0 };

  function el(id) { return document.getElementById(id); }

  function closeModal() {
    var modal = el('ai-modal');
    if (!modal) return;
    modal.hidden = true;
    var loading = modal.querySelector('.ai-loading');
    var err = modal.querySelector('.ai-error');
    var result = modal.querySelector('.ai-result');
    var custom = modal.querySelector('.ai-custom');
    if (loading) loading.hidden = true;
    if (err) { err.hidden = true; err.textContent = ''; }
    if (result) result.hidden = true;
    if (custom) custom.hidden = true;
    modal.querySelectorAll('.ai-tab').forEach(function (b) { b.classList.remove('busy'); });
  }

  function showError(msg) {
    var modal = el('ai-modal');
    var err = modal.querySelector('.ai-error');
    var loading = modal.querySelector('.ai-loading');
    if (loading) loading.hidden = true;
    if (err) { err.hidden = false; err.textContent = msg || '生成失败，请重试'; }
    modal.querySelectorAll('.ai-tab').forEach(function (b) { b.classList.remove('busy'); });
  }

  function readSelection() {
    if (!ta) return;
    sel.start = ta.selectionStart || 0;
    sel.end = ta.selectionEnd || 0;
    sel.text = ta.value.slice(sel.start, sel.end);
  }

  function request(action, content, instruction) {
    var modal = el('ai-modal');
    var loading = modal.querySelector('.ai-loading');
    var result = modal.querySelector('.ai-result');
    var err = modal.querySelector('.ai-error');
    var textarea = modal.querySelector('.ai-text');
    if (loading) loading.hidden = false;
    if (result) result.hidden = true;
    if (err) { err.hidden = true; err.textContent = ''; }
    modal.querySelectorAll('.ai-tab, .ai-apply button').forEach(function (b) { b.classList.add('busy'); });

    var body = new URLSearchParams();
    body.set('ai_action', action);
    body.set('content', content);
    body.set('title', titleInput ? titleInput.value : '');
    if (instruction) body.set('instruction', instruction);
    body.set('_csrf', modal.getAttribute('data-csrf') || '');

    fetch(modal.getAttribute('data-url'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      body: body.toString()
    }).then(function (r) { return r.json(); }).then(function (res) {
      if (!res || res.ok !== 1) { showError(res && res.message); return; }
      if (loading) loading.hidden = true;
      if (textarea) textarea.value = res.text || '';
      if (result) result.hidden = false;
      modal.querySelectorAll('.ai-tab, .ai-apply button').forEach(function (b) { b.classList.remove('busy'); });
      showApplyButtons();
    }).catch(function () { showError('网络错误，请重试'); });
  }

  function showApplyButtons() {
    var modal = el('ai-modal');
    var map = {
      'continue': ['insert', 'append'],
      'polish': pending.source === 'selection' ? ['replace_selection', 'insert'] : ['replace_all'],
      'format': ['replace_all'],
      'tags': ['tags'],
      'custom': pending.source === 'selection' ? ['replace_selection', 'insert'] : ['insert', 'replace_all']
    };
    var allowed = map[pending.action] || ['insert'];
    modal.querySelectorAll('[data-ai-apply]').forEach(function (b) {
      var mode = b.getAttribute('data-ai-apply');
      if (mode === 'copy') { b.hidden = false; return; }
      b.hidden = allowed.indexOf(mode) === -1;
    });
  }

  function runAction(action) {
    if (!ta) return;
    readSelection();
    var modal = el('ai-modal');
    var hint = modal.querySelector('.ai-hint');
    var custom = modal.querySelector('.ai-custom');
    if (custom) custom.hidden = action !== 'custom';
    if (action === 'custom') {
      var box = el('ai-instruction');
      if (box) box.focus();
      if (hint) hint.textContent = '输入指令后点「执行」。选中文字时只处理选中部分。';
      return;
    }
    var full = ta.value;
    var hasSel = sel.text.trim() !== '';
    var source = 'full';
    var content = full;
    if (action === 'format' || action === 'tags' || action === 'continue') {
      source = 'full';
      content = full;
    } else if (hasSel) {
      source = 'selection';
      content = sel.text;
    }
    if (action === 'continue' && full.trim() === '') { showError('正文为空，无法续写'); return; }
    if (action === 'format' && full.trim() === '') { showError('正文为空，无法排版'); return; }
    if (action === 'tags' && full.trim() === '') { showError('正文为空，无法生成标签'); return; }
    if (hint) {
      hint.textContent = (action === 'polish' && !hasSel)
        ? '未选中文字，将对全文润色；建议先选中要处理的段落。'
        : (action === 'polish' ? '将润色选中的 ' + sel.text.length + ' 个字符。' : '');
    }
    pending = { action: action, source: source, caret: sel.end || (sel.start === 0 ? full.length : sel.start) };
    request(action, content, '');
  }

  function applyResult(mode) {
    var modal = el('ai-modal');
    var text = (modal.querySelector('.ai-text') || {}).value || '';
    if (text === '') { showError('生成结果为空'); return; }
    if (mode === 'copy') {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () { closeModal(); });
      } else {
        var node = modal.querySelector('.ai-text');
        node.select();
        try { document.execCommand('copy'); } catch (e) {}
        closeModal();
      }
      return;
    }
    if (!ta) { closeModal(); return; }
    var v = ta.value;
    if (mode === 'replace_selection') {
      ta.value = v.slice(0, sel.start) + text + v.slice(sel.end);
      ta.selectionStart = ta.selectionEnd = sel.start + text.length;
    } else if (mode === 'insert') {
      var pos = pending.caret;
      if (pos > v.length) pos = v.length;
      var prefix = (pending.action === 'continue' && pos > 0 && !/\n$/.test(v.slice(0, pos))) ? '\n\n' : '';
      ta.value = v.slice(0, pos) + prefix + text + v.slice(pos);
      ta.selectionStart = ta.selectionEnd = pos + prefix.length + text.length;
    } else if (mode === 'append') {
      var sep = v === '' ? '' : (/\n$/.test(v) ? '\n' : '\n\n');
      ta.value = v + sep + text;
      ta.selectionStart = ta.selectionEnd = ta.value.length;
    } else if (mode === 'replace_all') {
      ta.value = text;
      ta.selectionStart = ta.selectionEnd = text.length;
    } else if (mode === 'tags') {
      var tags = text.replace(/[#\n\r]+/g, ',').replace(/，/g, ',').split(',').map(function (s) { return s.trim(); }).filter(function (s) { return s !== ''; });
      if (tagsInput) {
        tagsInput.value = tags.join(', ');
        tagsInput.dispatchEvent(new Event('input', { bubbles: true }));
      }
      closeModal();
      return;
    }
    ta.focus();
    ta.dispatchEvent(new Event('input', { bubbles: true }));
    closeModal();
  }

  function bind() {
    var modal = el('ai-modal');
    var openBtn = document.querySelector('[data-ai-open]');
    if (!modal || !openBtn) return;
    ta = document.querySelector('.md-editor textarea');
    tagsInput = document.querySelector('input[name="tags"]');
    titleInput = document.querySelector('input[name="title"]');
    if (!ta) return;

    openBtn.addEventListener('click', function (e) {
      e.preventDefault();
      readSelection();
      modal.hidden = false;
    });
    modal.addEventListener('click', function (e) {
      var t = e.target;
      if (!t || !t.closest) return;
      if (t.closest('[data-ai-close]')) { closeModal(); return; }
      var tab = t.closest('[data-ai-action]');
      if (tab) { runAction(tab.getAttribute('data-ai-action')); return; }
      var ap = t.closest('[data-ai-apply]');
      if (ap) { applyResult(ap.getAttribute('data-ai-apply')); return; }
      if (t.closest('#ai-custom-run')) {
        readSelection();
        var box = el('ai-instruction');
        var instruction = box ? box.value.trim() : '';
        if (instruction === '') { showError('请先输入自定义指令'); return; }
        var hasSel = sel.text.trim() !== '';
        pending = { action: 'custom', source: hasSel ? 'selection' : 'full', caret: sel.end || (sel.start === 0 ? ta.value.length : sel.start) };
        request('custom', hasSel ? sel.text : ta.value, instruction);
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !modal.hidden) closeModal();
    });
    // 服务商预设 → 自动填充 Base URL（仅当输入框为空或是已知预设值时，不覆盖自定义地址）。
    var provider = el('ai-provider');
    var baseurl = document.querySelector('input[name="baseurl"]');
    if (provider && baseurl) {
      provider.addEventListener('change', function () {
        var url = provider.options[provider.selectedIndex] ? (provider.options[provider.selectedIndex].getAttribute('data-baseurl') || '') : '';
        if (url === '') return;
        var known = [''];
        for (var i = 0; i < provider.options.length; i++) known.push(provider.options[i].getAttribute('data-baseurl') || '');
        if (known.indexOf(baseurl.value.trim()) !== -1) baseurl.value = url;
      });
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind);
  else bind();
}());
JS;
}

return [
    'id' => 'ai',
    'name' => 'AI 助手',
    'version' => '1.2.0',
    'description' => '接入 OpenAI 兼容接口：写文章页提供 AI 续写 / 润色 / 排版 / 自动生成标签，评论开启先审后显后可自动审核评论。',
    'author' => 'Mono',
    'assets' => ['css' => 'ai_css', 'js' => 'ai_js'],
    'hooks' => ['page.before_render' => 'ai_write_inject'],
    'routes' => ['ai_generate' => 'ai_generate'],
    'admin_tabs' => ['ai' => 'ai_admin'],
    'cron' => ['review' => ['callback' => 'ai_cron_review', 'interval' => 60]],
    'install' => 'ai_install',
    'uninstall' => 'ai_uninstall',
];
