<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{{ $title }}</title>
  <script>
    window.settings = {
      base_url: "/",
      title: "{{ $title }}",
      version: "{{ $version }}",
      logo: "{{ $logo }}",
      secure_path: "{{ $secure_path }}",
    };
  </script>
  @php
    $manifestPath = public_path('assets/admin/manifest.json');
    $manifest = file_exists($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : null;
    $entry = is_array($manifest) ? ($manifest['index.html'] ?? null) : null;
    $scripts = [];
    $styles = [];
    $locales = [];

    if (is_array($entry)) {
      $visited = [];
      $collectAssets = function ($chunkName) use (&$collectAssets, &$manifest, &$visited, &$scripts, &$styles) {
        if (isset($visited[$chunkName]) || !isset($manifest[$chunkName]) || !is_array($manifest[$chunkName])) {
          return;
        }

        $visited[$chunkName] = true;
        $chunk = $manifest[$chunkName];

        if (!empty($chunk['css']) && is_array($chunk['css'])) {
          foreach ($chunk['css'] as $cssFile) {
            $styles[$cssFile] = $cssFile;
          }
        }

        if (!empty($chunk['imports']) && is_array($chunk['imports'])) {
          foreach ($chunk['imports'] as $import) {
            $collectAssets($import);
          }
        }

        if (!empty($chunk['isEntry']) && !empty($chunk['file'])) {
          $scripts[$chunk['file']] = $chunk['file'];
        }
      };

      $collectAssets('index.html');
    }

    foreach (glob(public_path('assets/admin/locales/*.js')) ?: [] as $localeFile) {
      $locales[] = 'locales/' . basename($localeFile);
    }
    sort($locales);
  @endphp

  @if($entry && count($scripts) > 0)
    @foreach($styles as $css)
      <link rel="stylesheet" crossorigin href="/assets/admin/{{ $css }}" />
    @endforeach
    @foreach($locales as $locale)
      <script src="/assets/admin/{{ $locale }}"></script>
    @endforeach
    @foreach($scripts as $js)
      <script type="module" crossorigin src="/assets/admin/{{ $js }}"></script>
    @endforeach
  @else
    {{-- Fallback: hardcoded paths for backward compatibility --}}
    <script type="module" crossorigin src="/assets/admin/assets/index.js"></script>
    <link rel="stylesheet" crossorigin href="/assets/admin/assets/index.css" />
    <link rel="stylesheet" crossorigin href="/assets/admin/assets/vendor.css">
    <script src="/assets/admin/locales/en-US.js"></script>
    <script src="/assets/admin/locales/zh-CN.js"></script>
    <script src="/assets/admin/locales/ko-KR.js"></script>
  @endif
</head>

<body>
  <div id="root"></div>

  <script>
    // Xboard Lite admin cleanup.
    // The upstream admin UI is shipped as a compiled submodule, so removed
    // product features are filtered here while their backend routes remain disabled.
    (() => {
      const hiddenLabels = new Set([
        '订单管理', 'Order Management', 'Управление заказами', 'Заказы',
        '优惠券管理', 'Coupon Management', 'Купоны',
        '工单管理', 'Ticket Management', 'Тикеты',
        '公告管理', 'Notice Management', 'Объявления', 'Управление уведомлениями',
        '支付配置', 'Payment Configuration', 'Настройки оплаты', 'Платежные методы',
        '分配订单', 'Assign Order',
        'TA的订单', 'Orders',
        'TA的邀请', 'Invites',
        '发送邮件', 'Send Email'
      ]);

      const hiddenColumnLabels = new Set([
        '佣金', 'Commission',
        '新购', 'New Purchase',
        '续费', 'Renew',
        '价格', 'Price'
      ]);

      const hiddenPanelLabels = new Set([
        '今日收入', 'Today Income',
        '月收入', 'Monthly Income',
        '总收入', 'Total Income',
        '总订单', 'Total Orders',
        '待处理工单', 'Pending Tickets',
        '待处理佣金', 'Pending Commission',
        '收入概览', 'Revenue Overview', 'Income Overview',
        '价格设置', 'Pricing', 'Price Settings',
        '财务信息', 'Financial Information',
        '邀请信息', 'Invitation Information',
        '邀请&佣金设置', 'Invitation & Commission Settings'
      ]);

      const hiddenFieldLabels = new Set([
        '邀请人邮箱',
        '佣金余额',
        '佣金类型',
        '推荐返利比例',
        '专享折扣比例',
        '注册试用',
        '注册试用时长',
        '货币单位',
        '货币符号',
        '邮箱验证',
        '工单等待回复限制'
      ]);

      const hideExactMenuItems = () => {
        document.querySelectorAll('a,button,[role="menuitem"],li').forEach((node) => {
          const text = (node.textContent || '').replace(/\s+/g, ' ').trim();
          if (!hiddenLabels.has(text)) return;

          const target =
            node.closest('[role="menuitem"]') ||
            node.closest('a') ||
            node.closest('li') ||
            node;

          target.style.display = 'none';
          target.setAttribute('data-xboard-lite-hidden', '1');
        });
      };

      const hideRemovedPanels = () => {
        document.querySelectorAll('h1,h2,h3,h4,h5,h6,span,p,div').forEach((node) => {
          const text = (node.textContent || '').replace(/\s+/g, ' ').trim();
          if (!hiddenPanelLabels.has(text)) return;

          let target = node;
          for (let i = 0; i < 5 && target.parentElement; i++) {
            const parent = target.parentElement;
            const cls = String(parent.className || '');
            if (cls.includes('border') || cls.includes('rounded') || parent.getAttribute('data-slot') === 'card') {
              target = parent;
              break;
            }
            target = parent;
          }

          target.style.display = 'none';
          target.setAttribute('data-xboard-lite-hidden', '1');
        });
      };

      const hideRemovedFields = () => {
        document.querySelectorAll('label,span,p,div').forEach((node) => {
          const text = (node.textContent || '').replace(/\s+/g, ' ').trim();
          if (!hiddenFieldLabels.has(text)) return;

          let target = node.closest('label') || node;
          for (let i = 0; i < 4 && target.parentElement; i++) {
            const parent = target.parentElement;
            const parentText = (parent.textContent || '').replace(/\s+/g, ' ').trim();
            const controls = parent.querySelectorAll('input,select,textarea,button,[role="switch"],[role="combobox"]').length;

            target = parent;
            if (controls > 0 && parentText.length < 500) {
              break;
            }
          }

          target.style.display = 'none';
          target.setAttribute('data-xboard-lite-hidden', '1');
        });
      };

      const hideRemovedColumns = () => {
        document.querySelectorAll('table').forEach((table) => {
          const headers = Array.from(table.querySelectorAll('thead th'));
          headers.forEach((th, index) => {
            const text = (th.textContent || '').replace(/\s+/g, ' ').trim();
            if (!hiddenColumnLabels.has(text)) return;

            th.style.display = 'none';
            table.querySelectorAll('tbody tr').forEach((row) => {
              const cell = row.children[index];
              if (cell) cell.style.display = 'none';
            });
          });
        });
      };


      const extractBearer = (value, depth = 0) => {
        if (depth > 4 || value == null) return null;
        if (typeof value === 'string') {
          const trimmed = value.trim();
          if (/^Bearer\s+\S+$/.test(trimmed)) return trimmed;
          try { return extractBearer(JSON.parse(trimmed), depth + 1); } catch (_) {
            const match = trimmed.match(/Bearer\s+[A-Za-z0-9|._-]+/);
            return match ? match[0] : null;
          }
        }
        if (typeof value === 'object') {
          if (typeof value.auth_data === 'string' && value.auth_data.startsWith('Bearer ')) {
            return value.auth_data;
          }
          for (const item of Object.values(value)) {
            const found = extractBearer(item, depth + 1);
            if (found) return found;
          }
        }
        return null;
      };

      const findAdminAuth = () => {
        for (const storage of [window.localStorage, window.sessionStorage]) {
          try {
            for (let i = 0; i < storage.length; i++) {
              const found = extractBearer(storage.getItem(storage.key(i)));
              if (found) return found;
            }
          } catch (_) {}
        }
        return null;
      };

      const inviteApi = async (endpoint, options = {}) => {
        const auth = findAdminAuth();
        if (!auth) throw new Error('请先登录后台');
        const url = '/api/v2/' + encodeURIComponent(window.settings.secure_path) + '/access-invite/' + endpoint;
        const response = await fetch(url, {
          ...options,
          headers: {
            'Authorization': auth,
            'Accept': 'application/json',
            ...(options.body ? { 'Content-Type': 'application/json' } : {}),
            ...(options.headers || {})
          }
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.message || '请求失败');
        return payload;
      };

      const formatBytes = (value) => {
        const bytes = Number(value || 0);
        if (!Number.isFinite(bytes) || bytes <= 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
        const amount = bytes / Math.pow(1024, index);
        return amount.toFixed(amount >= 100 || index === 0 ? 0 : amount >= 10 ? 1 : 2) + ' ' + units[index];
      };

      const formatDateTime = (value) => {
        if (!value) return '-';
        const normalized = typeof value === 'number' ? value * 1000 : value;
        const date = new Date(normalized);
        return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString();
      };

      const dashboardApi = async () => {
        const auth = findAdminAuth();
        if (!auth) throw new Error('请先登录后台');
        const url = '/api/v2/' + encodeURIComponent(window.settings.secure_path) + '/stat/liteDashboard';
        const response = await fetch(url, {
          headers: {
            'Authorization': auth,
            'Accept': 'application/json'
          }
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.message || '仪表盘加载失败');
        return payload.data ?? payload;
      };

      const isDashboardPage = () => {
        const hash = (window.location.hash || '').replace(/^#\/?/, '').split('?')[0].replace(/\/$/, '');
        if (!hash || hash === 'dashboard') return true;

        const main = document.querySelector('main,[role="main"]');
        if (!main) return false;
        const heading = main.querySelector('h1,h2');
        const title = (heading?.textContent || '').trim();
        return title === '仪表盘' || title === 'Dashboard' || title === 'Панель управления';
      };

      const restoreUpstreamDashboard = () => {
        document.querySelectorAll('[data-xboard-lite-dashboard-hidden="1"]').forEach((node) => {
          node.style.display = node.getAttribute('data-xboard-lite-prev-display') || '';
          node.removeAttribute('data-xboard-lite-dashboard-hidden');
          node.removeAttribute('data-xboard-lite-prev-display');
        });
        document.getElementById('xboard-lite-dashboard')?.remove();
      };

      const ensureLiteDashboard = () => {
        if (!isDashboardPage() || !findAdminAuth()) {
          restoreUpstreamDashboard();
          return;
        }

        const main = document.querySelector('main,[role="main"]');
        if (!main) return;

        let panel = document.getElementById('xboard-lite-dashboard');
        if (!panel) {
          Array.from(main.children).forEach((child) => {
            if (child.id === 'xboard-lite-dashboard') return;
            child.setAttribute('data-xboard-lite-prev-display', child.style.display || '');
            child.setAttribute('data-xboard-lite-dashboard-hidden', '1');
            child.style.display = 'none';
          });

          if (!document.getElementById('xboard-lite-dashboard-style')) {
            const style = document.createElement('style');
            style.id = 'xboard-lite-dashboard-style';
            style.textContent =
              '#xboard-lite-dashboard{width:100%;padding:24px;box-sizing:border-box}' +
              '.xld-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:20px}' +
              '.xld-title{margin:0;font-size:26px;font-weight:700}.xld-subtitle{margin-top:4px;color:#6b7280;font-size:13px}' +
              '.xld-refresh{border:1px solid #d1d5db;background:transparent;border-radius:8px;padding:8px 12px;cursor:pointer}' +
              '.xld-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px}' +
              '.xld-card{border:1px solid rgba(127,127,127,.22);border-radius:12px;padding:15px;min-width:0}' +
              '.xld-label{font-size:12px;color:#6b7280;margin-bottom:7px}.xld-value{font-size:25px;font-weight:700;line-height:1.15}' +
              '.xld-note{font-size:12px;color:#6b7280;margin-top:5px}' +
              '.xld-sections{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}' +
              '.xld-section{border:1px solid rgba(127,127,127,.22);border-radius:12px;padding:15px;min-width:0;overflow:hidden}' +
              '.xld-section h3{margin:0 0 12px;font-size:15px}.xld-table{width:100%;border-collapse:collapse;font-size:13px}' +
              '.xld-table th,.xld-table td{text-align:left;padding:8px 6px;border-bottom:1px solid rgba(127,127,127,.16);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:220px}' +
              '.xld-table th{font-size:11px;color:#6b7280;font-weight:600}.xld-good{color:#16a34a}.xld-bad{color:#dc2626}.xld-muted{color:#6b7280}' +
              '.xld-error{padding:18px;border:1px solid rgba(220,38,38,.3);border-radius:10px;color:#dc2626}' +
              '@media(max-width:1100px){.xld-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.xld-sections{grid-template-columns:1fr}}' +
              '@media(max-width:640px){#xboard-lite-dashboard{padding:14px}.xld-grid{grid-template-columns:1fr 1fr}.xld-head{flex-direction:column}}';
            document.head.appendChild(style);
          }

          panel = document.createElement('section');
          panel.id = 'xboard-lite-dashboard';
          panel.innerHTML =
            '<div class="xld-head"><div><h1 class="xld-title">Xboard Lite</h1><div class="xld-subtitle">服务器 · 节点 · 用户 · 流量运行概览</div></div><button class="xld-refresh" type="button">刷新</button></div>' +
            '<div id="xld-content"><div class="xld-muted">正在加载运行数据…</div></div>';
          main.appendChild(panel);
          panel.querySelector('.xld-refresh').onclick = () => loadLiteDashboard(true);
        }

        loadLiteDashboard(false);
      };

      let dashboardLoading = false;
      let dashboardLoadedAt = 0;

      const loadLiteDashboard = async (force = false) => {
        const panel = document.getElementById('xboard-lite-dashboard');
        if (!panel || dashboardLoading) return;
        if (!force && Date.now() - dashboardLoadedAt < 30000) return;

        const content = panel.querySelector('#xld-content');
        dashboardLoading = true;
        try {
          const data = await dashboardApi();
          dashboardLoadedAt = Date.now();

          const cards = [
            ['服务器', (data.machines?.online ?? 0) + ' / ' + (data.machines?.total ?? 0), '在线 / 总数'],
            ['节点', (data.nodes?.online ?? 0) + ' / ' + (data.nodes?.total ?? 0), '在线 / 总数'],
            ['用户', data.users?.total ?? 0, '活跃 ' + (data.users?.active ?? 0)],
            ['在线用户', data.users?.online ?? 0, '在线设备 ' + (data.users?.online_devices ?? 0)],
            ['今日流量', formatBytes(data.traffic?.today?.total), '↑ ' + formatBytes(data.traffic?.today?.upload) + '  ↓ ' + formatBytes(data.traffic?.today?.download)],
            ['本月流量', formatBytes(data.traffic?.month?.total), '↑ ' + formatBytes(data.traffic?.month?.upload) + '  ↓ ' + formatBytes(data.traffic?.month?.download)],
            ['累计流量', formatBytes(data.traffic?.total?.total), '历史节点流量'],
            ['更新时间', formatDateTime(data.generated_at), '30 秒内使用缓存']
          ];

          const cardHtml = cards.map(([label, value, note]) =>
            '<div class="xld-card"><div class="xld-label">' + escapeHtml(String(label)) + '</div><div class="xld-value">' + escapeHtml(String(value)) + '</div><div class="xld-note">' + escapeHtml(String(note)) + '</div></div>'
          ).join('');

          const machineRows = (data.machines?.items || []).map((item) =>
            '<tr><td>' + escapeHtml(item.name || ('#' + item.id)) + '</td><td class="' + (item.is_online ? 'xld-good' : 'xld-bad') + '">' + (item.is_online ? '在线' : '离线') + '</td><td>' + (item.servers_count ?? 0) + '</td><td class="xld-muted">' + escapeHtml(formatDateTime(item.last_seen_at)) + '</td></tr>'
          ).join('') || '<tr><td colspan="4" class="xld-muted">暂无服务器</td></tr>';

          const serverRows = (data.server_rank || []).map((item, index) =>
            '<tr><td>' + (index + 1) + '</td><td>' + escapeHtml(item.name || '-') + '</td><td>' + escapeHtml(item.type || '-') + '</td><td>' + escapeHtml(formatBytes(item.total)) + '</td></tr>'
          ).join('') || '<tr><td colspan="4" class="xld-muted">今日暂无流量</td></tr>';

          const userRows = (data.user_rank || []).map((item, index) =>
            '<tr><td>' + (index + 1) + '</td><td>' + escapeHtml(item.email || '-') + '</td><td>' + escapeHtml(formatBytes(item.total)) + '</td></tr>'
          ).join('') || '<tr><td colspan="3" class="xld-muted">今日暂无流量</td></tr>';

          const recentRows = (data.recent_users || []).map((item) =>
            '<tr><td>' + escapeHtml(item.email || '-') + '</td><td>' + escapeHtml(item.plan || '未分配') + '</td><td class="xld-muted">' + escapeHtml(formatDateTime(item.created_at)) + '</td></tr>'
          ).join('') || '<tr><td colspan="3" class="xld-muted">暂无用户</td></tr>';

          const expiringRows = (data.expiring_users || []).map((item) =>
            '<tr><td>' + escapeHtml(item.email || '-') + '</td><td>' + escapeHtml(item.plan || '未分配') + '</td><td>' + escapeHtml(formatDateTime(item.expired_at)) + '</td></tr>'
          ).join('') || '<tr><td colspan="3" class="xld-muted">未来 7 天无人到期</td></tr>';

          content.innerHTML =
            '<div class="xld-grid">' + cardHtml + '</div>' +
            '<div class="xld-sections">' +
              '<section class="xld-section"><h3>服务器状态</h3><table class="xld-table"><thead><tr><th>服务器</th><th>状态</th><th>节点</th><th>最后心跳</th></tr></thead><tbody>' + machineRows + '</tbody></table></section>' +
              '<section class="xld-section"><h3>今日节点流量排行</h3><table class="xld-table"><thead><tr><th>#</th><th>节点</th><th>协议</th><th>流量</th></tr></thead><tbody>' + serverRows + '</tbody></table></section>' +
              '<section class="xld-section"><h3>今日用户流量排行</h3><table class="xld-table"><thead><tr><th>#</th><th>用户</th><th>流量</th></tr></thead><tbody>' + userRows + '</tbody></table></section>' +
              '<section class="xld-section"><h3>最近注册用户</h3><table class="xld-table"><thead><tr><th>用户</th><th>套餐</th><th>注册时间</th></tr></thead><tbody>' + recentRows + '</tbody></table></section>' +
              '<section class="xld-section"><h3>7 天内到期</h3><table class="xld-table"><thead><tr><th>用户</th><th>套餐</th><th>到期时间</th></tr></thead><tbody>' + expiringRows + '</tbody></table></section>' +
            '</div>';
        } catch (error) {
          if (content) content.innerHTML = '<div class="xld-error">' + escapeHtml(error?.message || '仪表盘加载失败') + '</div>';
        } finally {
          dashboardLoading = false;
        }
      };

      const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

      const inviteDate = (value) => {
        if (!value) return '-';
        const date = new Date(typeof value === 'number' ? value * 1000 : value);
        return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString();
      };

      const ensureAccessInviteButton = () => {
        if (!findAdminAuth() || document.getElementById('xboard-lite-invite-button')) return;

        const style = document.createElement('style');
        style.id = 'xboard-lite-invite-style';
        style.textContent =
          '#xboard-lite-invite-button{position:fixed;right:22px;bottom:22px;z-index:9997;border:1px solid #374151;border-radius:10px;padding:10px 14px;cursor:pointer;background:#111827;color:#fff;box-shadow:0 8px 24px rgba(0,0,0,.18)}' +
          '#xboard-lite-invite-overlay{position:fixed;inset:0;z-index:9998;display:none;align-items:center;justify-content:center;padding:24px;background:rgba(0,0,0,.58)}' +
          '#xboard-lite-invite-panel{width:min(820px,96vw);max-height:82vh;overflow:auto;border-radius:14px;padding:20px;background:#111827;color:#f9fafb;box-shadow:0 24px 70px rgba(0,0,0,.4)}' +
          '#xboard-lite-invite-panel button,#xboard-lite-invite-panel input{font:inherit}.xli-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}' +
          '.xli-row input{width:80px;padding:8px;border-radius:8px;border:1px solid #374151;background:#0b1220;color:#fff}' +
          '.xli-action{padding:8px 11px;border-radius:8px;border:1px solid #374151;background:#1f2937;color:#fff;cursor:pointer}' +
          '.xli-table{width:100%;border-collapse:collapse;margin-top:16px}.xli-table th,.xli-table td{text-align:left;padding:10px 8px;border-bottom:1px solid #273244}' +
          '.xli-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}.xli-muted{color:#9ca3af;font-size:13px}.xli-ok{color:#86efac}.xli-used{color:#9ca3af}#xli-message{min-height:20px;margin-top:10px}';
        document.head.appendChild(style);

        const button = document.createElement('button');
        button.id = 'xboard-lite-invite-button';
        button.type = 'button';
        button.textContent = '邀请码管理';

        const overlay = document.createElement('div');
        overlay.id = 'xboard-lite-invite-overlay';
        overlay.innerHTML =
          '<div id="xboard-lite-invite-panel">' +
            '<div class="xli-row" style="justify-content:space-between">' +
              '<div><h2 style="margin:0">邀请码管理</h2><div class="xli-muted">一次性准入码，不产生邀请关系或返佣</div></div>' +
              '<button type="button" class="xli-action" id="xli-close">关闭</button>' +
            '</div>' +
            '<div class="xli-row" style="margin-top:18px">' +
              '<label>生成数量 <input id="xli-count" type="number" min="1" max="100" value="5"></label>' +
              '<button type="button" class="xli-action" id="xli-generate">生成</button>' +
              '<button type="button" class="xli-action" id="xli-refresh">刷新</button>' +
            '</div>' +
            '<div id="xli-message" class="xli-muted"></div>' +
            '<table class="xli-table"><thead><tr><th>邀请码</th><th>状态</th><th>创建时间</th><th>操作</th></tr></thead><tbody id="xli-body"></tbody></table>' +
          '</div>';

        document.body.append(button, overlay);

        const message = overlay.querySelector('#xli-message');
        const body = overlay.querySelector('#xli-body');

        const setMessage = (text, error = false) => {
          message.textContent = text || '';
          message.style.color = error ? '#fca5a5' : '#9ca3af';
        };

        const loadInvites = async () => {
          setMessage('正在加载…');
          try {
            const payload = await inviteApi('fetch');
            const root = payload.data ?? payload;
            const rows = Array.isArray(root) ? root : (root.data ?? root.items ?? []);
            body.replaceChildren();

            for (const item of rows) {
              const tr = document.createElement('tr');
              const codeTd = document.createElement('td');
              codeTd.className = 'xli-code';
              codeTd.textContent = item.code || '-';

              const statusTd = document.createElement('td');
              statusTd.textContent = item.status ? '已使用' : '可用';
              statusTd.className = item.status ? 'xli-used' : 'xli-ok';

              const dateTd = document.createElement('td');
              dateTd.textContent = inviteDate(item.created_at);

              const actionTd = document.createElement('td');
              const copy = document.createElement('button');
              copy.type = 'button';
              copy.className = 'xli-action';
              copy.textContent = '复制';
              copy.onclick = async () => {
                const text = item.code || '';
                try {
                  if (!navigator.clipboard || !window.isSecureContext) throw new Error('clipboard unavailable');
                  await navigator.clipboard.writeText(text);
                } catch (_) {
                  const textarea = document.createElement('textarea');
                  textarea.value = text;
                  textarea.setAttribute('readonly', '');
                  textarea.style.position = 'fixed';
                  textarea.style.opacity = '0';
                  document.body.appendChild(textarea);
                  textarea.select();
                  document.execCommand('copy');
                  textarea.remove();
                }
                setMessage('已复制 ' + text);
              };
              actionTd.appendChild(copy);

              if (!item.status) {
                const del = document.createElement('button');
                del.type = 'button';
                del.className = 'xli-action';
                del.style.marginLeft = '6px';
                del.textContent = '删除';
                del.onclick = async () => {
                  try {
                    await inviteApi('drop', { method: 'POST', body: JSON.stringify({ id: item.id }) });
                    await loadInvites();
                  } catch (e) { setMessage(e.message, true); }
                };
                actionTd.appendChild(del);
              }

              tr.append(codeTd, statusTd, dateTd, actionTd);
              body.appendChild(tr);
            }
            setMessage('共显示 ' + rows.length + ' 条');
          } catch (e) {
            setMessage(e.message, true);
          }
        };

        button.onclick = () => {
          overlay.style.display = 'flex';
          loadInvites();
        };
        overlay.querySelector('#xli-close').onclick = () => overlay.style.display = 'none';
        overlay.addEventListener('click', event => {
          if (event.target === overlay) overlay.style.display = 'none';
        });
        overlay.querySelector('#xli-refresh').onclick = loadInvites;
        overlay.querySelector('#xli-generate').onclick = async () => {
          const field = overlay.querySelector('#xli-count');
          const count = Math.max(1, Math.min(100, Number(field.value) || 1));
          setMessage('正在生成…');
          try {
            const payload = await inviteApi('generate', { method: 'POST', body: JSON.stringify({ count }) });
            const codes = payload.data ?? [];
            setMessage(Array.isArray(codes) && codes.length ? '已生成：' + codes.join('、') : '生成成功');
            await loadInvites();
          } catch (e) {
            setMessage(e.message, true);
          }
        };
      };

      let scheduled = false;
      const clean = () => {
        if (scheduled) return;
        scheduled = true;
        requestAnimationFrame(() => {
          scheduled = false;
          hideExactMenuItems();
          hideRemovedPanels();
          hideRemovedFields();
          hideRemovedColumns();
          ensureAccessInviteButton();
          ensureLiteDashboard();
        });
      };

      const observer = new MutationObserver(clean);
      window.addEventListener('DOMContentLoaded', () => {
        clean();
        observer.observe(document.body, { childList: true, subtree: true });
        window.addEventListener('hashchange', clean);
        window.addEventListener('popstate', clean);
      });
    })();
  </script>

</body>

</html>
