/* Mono 核心脚本：flash 提示、确认删除、移动端交互。保持原生无依赖。 */
(function () {
  'use strict';

  // 显示服务端通过 window.__pageFlash 传递的一次性提示（{m: 文案, t: ok|error}）；顶部浮层，无需滚回页顶。
  function showFlash(flash) {
    if (!flash || !flash.m) return;
    var box = document.createElement('div');
    box.className = 'flash' + (flash.t === 'error' ? ' error' : '');
    box.setAttribute('role', 'status');
    box.textContent = flash.m;
    document.body.appendChild(box);
    setTimeout(function () { box.classList.add('out'); }, 3200);
    setTimeout(function () { box.remove(); }, 3650);
  }

  // —— 站内确认弹窗（data-confirm 统一入口，替代浏览器原生 confirm）——
  // 表单/链接挂 data-confirm="文案" 后，提交/点击先弹站内确认框；表单可另附：
  // data-confirm-option="勾选项文案"（显示附加勾选框）与
  // data-confirm-option-set="字段=值[;字段=值]"（勾选时写入/覆盖对应隐藏字段，如卸载时删除数据）。
  var confirmUI = null;

  function ensureConfirmUI() {
    if (confirmUI) return confirmUI;
    var mask = document.createElement('div');
    mask.className = 'confirm-mask';
    mask.hidden = true;
    mask.innerHTML = '<div class="confirm-box" role="dialog" aria-modal="true">'
      + '<p class="confirm-msg"></p>'
      + '<label class="confirm-option" hidden><input type="checkbox"><span></span></label>'
      + '<div class="confirm-actions">'
      + '<button type="button" class="btn sm ghost" data-role="cancel">取消</button>'
      + '<button type="button" class="btn sm danger" data-role="ok">确定</button>'
      + '</div></div>';
    document.body.appendChild(mask);
    confirmUI = {
      mask: mask,
      msg: mask.querySelector('.confirm-msg'),
      option: mask.querySelector('.confirm-option'),
      optInput: mask.querySelector('.confirm-option input'),
      optText: mask.querySelector('.confirm-option span'),
      ok: mask.querySelector('[data-role="ok"]'),
      cancel: mask.querySelector('[data-role="cancel"]'),
      pending: null
    };
    // 勾选时按 "字段=值" 规格写入/覆盖表单隐藏字段；未勾选保持表单初始值（安全默认）。
    function applyOptionSet(form, spec) {
      spec.split(';').forEach(function (pair) {
        var i = pair.indexOf('=');
        if (i <= 0) return;
        var name = pair.slice(0, i).trim();
        var val = pair.slice(i + 1).trim();
        var field = form.querySelector('input[name="' + name + '"]');
        if (!field) {
          field = document.createElement('input');
          field.type = 'hidden';
          field.name = name;
          form.appendChild(field);
        }
        field.value = val;
      });
    }
    function settle(ok) {
      var p = confirmUI.pending;
      if (!p) return;
      var checked = confirmUI.optInput.checked;
      confirmUI.mask.hidden = true;
      confirmUI.pending = null;
      if (!ok) return;
      if (p.form) {
        if (checked && p.set) applyOptionSet(p.form, p.set);
        p.form.submit(); // 原生 submit() 不再派发 submit 事件，不会二次弹窗
      } else if (p.href) {
        if (p.blank) window.open(p.href); else window.location.href = p.href;
      }
    }
    confirmUI.ok.addEventListener('click', function () { settle(true); });
    confirmUI.cancel.addEventListener('click', function () { settle(false); });
    mask.addEventListener('click', function (e) { if (e.target === mask) settle(false); });
    document.addEventListener('keydown', function (e) {
      if (!mask.hidden && e.key === 'Escape') { e.preventDefault(); settle(false); }
    });
    return confirmUI;
  }

  function askConfirm(el, msg) {
    var ui = ensureConfirmUI();
    ui.msg.textContent = msg;
    var opt = el.getAttribute('data-confirm-option') || '';
    ui.optInput.checked = false;
    ui.optText.textContent = opt;
    ui.option.hidden = !opt;
    ui.pending = el.tagName === 'FORM'
      ? { form: el, set: el.getAttribute('data-confirm-option-set') || '' }
      : { href: el.getAttribute('href') || '', blank: el.getAttribute('target') === '_blank' };
    ui.mask.hidden = false;
    ui.ok.focus(); // 默认聚焦确认键：Enter 确认、Esc 取消，与原生 confirm 习惯一致
  }

  // 为带 data-confirm 的表单/链接绑定二次确认（站内弹窗，视觉与主题自适应）。
  function bindConfirm() {
    document.addEventListener('submit', function (e) {
      var form = e.target;
      var msg = form.getAttribute && form.getAttribute('data-confirm');
      if (!msg) return;
      e.preventDefault();
      askConfirm(form, msg);
    });
    document.addEventListener('click', function (e) {
      var el = e.target.closest ? e.target.closest('a[data-confirm]') : null;
      if (!el) return;
      var msg = el.getAttribute('data-confirm');
      if (!msg) return;
      e.preventDefault();
      askConfirm(el, msg);
    });
  }

  // 代码块复制按钮：读取同容器内 <pre><code> 的纯文本写入剪贴板。
  function bindCodeCopy() {
    document.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('.codeblock-copy') : null;
      if (!btn) return;
      var wrap = btn.closest('.codeblock');
      var code = wrap && wrap.querySelector('pre code');
      if (!code) return;
      var text = code.textContent;
      function done() {
        btn.textContent = '已复制';
        btn.classList.add('copied');
        setTimeout(function () { btn.textContent = '复制'; btn.classList.remove('copied'); }, 1600);
      }
      function fallback() {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (_) { /* 忽略 */ }
        document.body.removeChild(ta);
        done();
      }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, fallback);
      } else {
        fallback();
      }
    });
  }

  // 深浅色主题切换：手动偏好写 localStorage，未手动时跟随系统（见 head 内联脚本）。
  function bindThemeToggle() {
    document.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('.theme-toggle') : null;
      if (!btn) return;
      var dark = document.documentElement.classList.toggle('dark');
      try { localStorage.setItem('mono-theme', dark ? 'dark' : 'light'); } catch (_) { /* 忽略 */ }
    });
  }

  function fireInput(ta) { ta.dispatchEvent(new Event('input', { bubbles: true })); }

  // 用前后缀包裹选区（无选区时插入占位文本并选中），插入后触发 input 以刷新字数。
  function mdSurround(ta, pre, suf, ph) {
    var s = ta.selectionStart, e = ta.selectionEnd, v = ta.value;
    var sel = v.slice(s, e) || ph || '';
    ta.value = v.slice(0, s) + pre + sel + suf + v.slice(e);
    ta.focus();
    ta.selectionStart = s + pre.length;
    ta.selectionEnd = s + pre.length + sel.length;
    fireInput(ta);
  }

  // 给选区覆盖的每一行加前缀（标题/引用/列表）；ordered 时自动编号。
  function mdPrefixLines(ta, prefix, ordered) {
    var s = ta.selectionStart, e = ta.selectionEnd, v = ta.value;
    var ls = v.lastIndexOf('\n', s - 1) + 1;
    var le = v.indexOf('\n', e); if (le === -1) le = v.length;
    var n = 1;
    var out = v.slice(ls, le).split('\n').map(function (ln) { return (ordered ? (n++) + '. ' : prefix) + ln; }).join('\n');
    ta.value = v.slice(0, ls) + out + v.slice(le);
    ta.focus();
    ta.selectionStart = ls; ta.selectionEnd = ls + out.length;
    fireInput(ta);
  }

  // 在光标处另起一行插入整块文本（代码块/图表/表格/分割线）。
  function mdBlock(ta, text) {
    var s = ta.selectionStart, e = ta.selectionEnd, v = ta.value;
    var pre = (s > 0 && v.charAt(s - 1) !== '\n') ? '\n' : '';
    ta.value = v.slice(0, s) + pre + text + v.slice(e);
    ta.focus();
    ta.selectionStart = ta.selectionEnd = s + pre.length + text.length;
    fireInput(ta);
  }

  function mdAction(ta, act) {
    switch (act) {
      case 'bold': mdSurround(ta, '**', '**', '加粗文本'); break;
      case 'italic': mdSurround(ta, '*', '*', '斜体文本'); break;
      case 'strike': mdSurround(ta, '~~', '~~', '删除线文本'); break;
      case 'code': mdSurround(ta, '`', '`', 'code'); break;
      case 'h2': mdPrefixLines(ta, '## '); break;
      case 'h3': mdPrefixLines(ta, '### '); break;
      case 'quote': mdPrefixLines(ta, '> '); break;
      case 'ul': mdPrefixLines(ta, '- '); break;
      case 'ol': mdPrefixLines(ta, '', true); break;
      case 'link': mdSurround(ta, '[', '](https://)', '链接文字'); break;
      case 'image': mdSurround(ta, '![', '](https://)', '图片描述'); break;
      case 'codeblock': mdBlock(ta, '```\n代码\n```\n'); break;
      case 'mermaid': mdBlock(ta, '```mermaid\nflowchart TD\n    A[开始] --> B[结束]\n```\n'); break;
      case 'table': mdBlock(ta, '| 列1 | 列2 | 列3 |\n| --- | --- | --- |\n|  |  |  |\n'); break;
      case 'hr': mdBlock(ta, '\n---\n'); break;
    }
  }

  // Markdown 编辑器：工具栏、快捷键、Tab 缩进、字数、分栏实时预览、全屏。
  function bindMarkdownEditor() {
    var editor = document.querySelector('.md-editor');
    if (!editor) return;
    var ta = editor.querySelector('textarea');
    var count = editor.querySelector('.md-count');
    var preview = editor.querySelector('.md-preview');
    if (!ta) return;
    var update = function () { if (count) count.textContent = ta.value.length + ' 字'; };
    update();
    // 分栏预览：防抖请求服务端渲染写入右栏，失败静默等下次输入重试。
    var renderTimer = null;
    var renderPreview = function () {
      var url = editor.getAttribute('data-preview-url');
      if (!url) return;
      var form = editor.closest('form');
      var csrf = form && form.querySelector('input[name="_csrf"]');
      var body = new URLSearchParams();
      body.set('content', ta.value);
      if (csrf) body.set('_csrf', csrf.value);
      fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString(),
      }).then(function (r) { return r.json(); })
        .then(function (res) { if (res && res.ok === 1 && preview) preview.innerHTML = res.html; })
        .catch(function () { /* 忽略 */ });
    };
    var queueRender = function () { clearTimeout(renderTimer); renderTimer = setTimeout(renderPreview, 350); };
    var setSplit = function (on) {
      editor.classList.toggle('split', on);
      var t = editor.querySelector('.md-toggle[data-role="split"]');
      if (t) t.setAttribute('aria-pressed', on ? 'true' : 'false');
      if (on) renderPreview();
    };
    var setFull = function (on) {
      editor.classList.toggle('full', on);
      var t = editor.querySelector('.md-toggle[data-role="full"]');
      if (t) t.setAttribute('aria-pressed', on ? 'true' : 'false');
      document.body.style.overflow = on ? 'hidden' : '';
      if (on) ta.focus();
    };
    ta.addEventListener('input', function () {
      update();
      if (editor.classList.contains('split')) queueRender();
    });
    editor.addEventListener('click', function (e) {
      var tog = e.target.closest ? e.target.closest('.md-toggle') : null;
      if (tog) {
        e.preventDefault();
        if (tog.getAttribute('data-role') === 'split') setSplit(!editor.classList.contains('split'));
        else setFull(!editor.classList.contains('full'));
        return;
      }
      var btn = e.target.closest ? e.target.closest('.md-btn') : null;
      if (btn) { e.preventDefault(); mdAction(ta, btn.getAttribute('data-md')); }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && editor.classList.contains('full')) setFull(false);
    });
    ta.addEventListener('keydown', function (e) {
      if (e.key === 'Tab') { e.preventDefault(); mdSurround(ta, '  ', '', ''); return; }
      if (!(e.ctrlKey || e.metaKey)) return;
      var k = (e.key || '').toLowerCase();
      if (k === 'b') { e.preventDefault(); mdAction(ta, 'bold'); }
      else if (k === 'i') { e.preventDefault(); mdAction(ta, 'italic'); }
      else if (k === 'k') { e.preventDefault(); mdAction(ta, 'link'); }
    });
  }

  // 写文章页快捷新建分类：AJAX 提交，成功后把新分类插入下拉并选中，不刷新页面（保留草稿）。
  function bindCatQuickAdd() {
    document.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('.cat-add') : null;
      if (!btn) return;
      var name = window.prompt('新分类名称');
      if (!name || !name.trim()) return;
      var form = btn.closest('form');
      var csrf = form && form.querySelector('input[name="_csrf"]');
      var body = new URLSearchParams();
      body.set('name', name.trim());
      if (csrf) body.set('_csrf', csrf.value);
      fetch(btn.getAttribute('data-url') || (form ? form.action : window.location.href), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString(),
      }).then(function (r) { return r.json(); }).then(function (res) {
        if (!res || res.ok !== 1) { window.alert((res && res.message) || '创建失败'); return; }
        var select = form && form.querySelector('select[name="category_id"]');
        if (!select) return;
        var opt = select.querySelector('option[value="' + res.id + '"]');
        if (!opt) { opt = document.createElement('option'); opt.value = res.id; opt.textContent = res.name; select.appendChild(opt); }
        select.value = String(res.id);
      }).catch(function () { window.alert('网络错误，请重试'); });
    });
  }

  // 头像子菜单：点击展开/收起，点击外部或 Esc 关闭。
  function bindUserMenu() {
    var menu = document.querySelector('.user-menu');
    if (!menu) return;
    var btn = menu.querySelector('.user-menu-btn');
    if (!btn) return;
    var setOpen = function (open) {
      menu.classList.toggle('open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      setOpen(!menu.classList.contains('open'));
    });
    document.addEventListener('click', function (e) {
      if (menu.classList.contains('open') && !menu.contains(e.target)) setOpen(false);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') setOpen(false);
    });
  }

  // 站内锚点点击平滑滚动：页面加载 / 302 跳转（如提交评论后回 #comment）的锚点定位保持瞬时到位，
  // 只有用户点击「当前页锚点链接」才平滑滚动；尊重 prefers-reduced-motion。
  function bindAnchorScroll() {
    document.addEventListener('click', function (e) {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      var a = e.target.closest ? e.target.closest('a[href*="#"]') : null;
      if (!a || !a.hash || a.hash === '#') return;
      // 仅拦截「当前页」锚点；跨页链接交给浏览器正常导航。
      if (a.pathname !== location.pathname || a.search !== location.search) return;
      var id = a.hash.slice(1);
      try { id = decodeURIComponent(id); } catch (_) { return; }
      var target = document.getElementById(id);
      if (!target) return;
      e.preventDefault();
      var behavior = 'smooth';
      try {
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) behavior = 'auto';
      } catch (_) { /* 忽略 */ }
      target.scrollIntoView({ behavior: behavior, block: 'start' });
      if (history.pushState) history.pushState(null, '', a.hash);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    showFlash(window.__pageFlash);
    bindConfirm();
    bindCodeCopy();
    bindThemeToggle();
    bindMarkdownEditor();
    bindCatQuickAdd();
    bindUserMenu();
    bindAnchorScroll();
  });
}());
