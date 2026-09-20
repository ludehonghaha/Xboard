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
        '余额', 'Balance',
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
        '余额',
        '邀请人',
        '邀请人ID',
        '邀请人邮箱',
        '佣金余额',
        '佣金类型',
        '推荐返利比例',
        '专享折扣比例',
        '佣金类型',
        '佣金比例',
        '推荐返利比例',
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

      const ensureLiteNavigation = () => {
        // Rename the old commerce-oriented plan label.
        document.querySelectorAll('a,button,[role="menuitem"],span').forEach((node) => {
          const text = (node.textContent || '').replace(/\s+/g, ' ').trim();
          if (text === '套餐管理') {
            const leaf = Array.from(node.querySelectorAll('span')).find((span) => (span.textContent || '').trim() === '套餐管理');
            if (leaf) leaf.textContent = '服务套餐';
            else if (node.children.length === 0) node.textContent = '服务套餐';
          } else if (text === 'Plan Management') {
            const leaf = Array.from(node.querySelectorAll('span')).find((span) => (span.textContent || '').trim() === 'Plan Management');
            if (leaf) leaf.textContent = 'Service Plans';
            else if (node.children.length === 0) node.textContent = 'Service Plans';
          }
        });

        const fallback = document.getElementById('xboard-lite-invite-button');
        const existing = document.getElementById('xboard-lite-invite-nav');
        if (existing?.isConnected) {
          if (fallback) fallback.style.display = 'none';
          return;
        }

        const labels = new Set(['用户管理', 'User Management', 'Пользователи']);
        const candidates = Array.from(document.querySelectorAll('a,button,[role="menuitem"]'));
        const source = candidates.find((node) => labels.has((node.textContent || '').replace(/\s+/g, ' ').trim()));

        if (!source || !source.parentElement || !fallback) {
          if (fallback) fallback.style.display = '';
          return;
        }

        const nav = source.cloneNode(true);
        nav.id = 'xboard-lite-invite-nav';
        nav.removeAttribute('href');
        nav.removeAttribute('aria-current');
        nav.removeAttribute('data-state');
        nav.querySelectorAll('[aria-current]').forEach((node) => node.removeAttribute('aria-current'));

        const spans = Array.from(nav.querySelectorAll('span'));
        const textSpan = spans.find((span) => labels.has((span.textContent || '').replace(/\s+/g, ' ').trim()));
        if (textSpan) textSpan.textContent = '邀请码管理';
        else nav.textContent = '邀请码管理';

        nav.addEventListener('click', (event) => {
          event.preventDefault();
          event.stopPropagation();
          fallback.click();
        });

        source.parentElement.insertBefore(nav, source.nextSibling);
        fallback.style.display = 'none';
      };


      const noBrandAdminApi = async (path, options = {}) => {
        const auth = findAdminAuth();
        if (!auth) throw new Error('请先登录后台');
        const url = '/api/v2/' + encodeURIComponent(window.settings.secure_path) + '/' + path;
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
        if (!response.ok) {
          const message = payload.message || payload.error || payload.data?.message || '请求失败';
          throw new Error(typeof message === 'string' ? message : '请求失败');
        }
        return payload.data ?? payload;
      };

      const ensureNoBrandManager = () => {
        if (!findAdminAuth() || document.getElementById('xboard-lite-nobrand-button')) return;

        if (!document.getElementById('xboard-lite-nobrand-style')) {
          const style = document.createElement('style');
          style.id = 'xboard-lite-nobrand-style';
          style.textContent =
            '#xboard-lite-nobrand-button{position:fixed;right:22px;bottom:72px;z-index:9997;border:1px solid #374151;border-radius:10px;padding:10px 14px;cursor:pointer;background:#0f172a;color:#e5e7eb;box-shadow:0 8px 24px rgba(0,0,0,.2)}' +
            '#xboard-lite-nobrand-overlay{position:fixed;inset:0;z-index:9999;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(0,0,0,.68)}' +
            '#xboard-lite-nobrand-panel{width:min(1040px,97vw);max-height:90vh;overflow:auto;border:1px solid #273449;border-radius:16px;padding:20px;background:#0b1220;color:#e5e7eb;box-shadow:0 28px 80px rgba(0,0,0,.5)}' +
            '.xnb-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:18px}.xnb-head h2{margin:0;font-size:22px}.xnb-muted{color:#94a3b8;font-size:12px}' +
            '.xnb-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.xnb-card{border:1px solid #243047;border-radius:12px;padding:15px;background:#0f172a}' +
            '.xnb-card h3{margin:0 0 12px;font-size:15px}.xnb-row{display:grid;grid-template-columns:145px minmax(0,1fr);gap:10px;align-items:center;margin:9px 0}' +
            '.xnb-row label{font-size:12px;color:#cbd5e1}.xnb-input,.xnb-select,.xnb-textarea{width:100%;box-sizing:border-box;border:1px solid #334155;border-radius:8px;background:#020617;color:#e5e7eb;padding:8px 10px;font:inherit}' +
            '.xnb-textarea{min-height:94px;resize:vertical;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px}' +
            '.xnb-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:13px}.xnb-btn{border:1px solid #334155;border-radius:8px;padding:8px 11px;background:#1e293b;color:#f8fafc;cursor:pointer}.xnb-btn-primary{background:#1d4ed8;border-color:#2563eb}.xnb-btn-warn{background:#7c2d12;border-color:#9a3412}' +
            '.xnb-status{min-height:20px;margin-top:10px;font-size:12px;color:#94a3b8}.xnb-ok{color:#86efac}.xnb-error{color:#fca5a5}.xnb-badge{display:inline-block;border:1px solid #334155;border-radius:999px;padding:3px 8px;font-size:11px;color:#cbd5e1;margin-right:5px}' +
            '.xnb-check{display:flex;align-items:center;gap:8px}.xnb-check input{width:16px;height:16px}.xnb-command{margin-top:12px;padding-top:12px;border-top:1px solid #243047}' +
            '@media(max-width:820px){.xnb-grid{grid-template-columns:1fr}.xnb-row{grid-template-columns:1fr}.xnb-head{flex-direction:column}}';
          document.head.appendChild(style);
        }

        const button = document.createElement('button');
        button.id = 'xboard-lite-nobrand-button';
        button.type = 'button';
        button.textContent = 'NoBrand Agent';

        const overlay = document.createElement('div');
        overlay.id = 'xboard-lite-nobrand-overlay';
        overlay.innerHTML =
          '<div id="xboard-lite-nobrand-panel">' +
            '<div class="xnb-head"><div><h2>NoBrand Hybrid Agent</h2><div class="xnb-muted">Xboard-Node 与 NoBrand Mieru 专属实例并行管理</div></div><button type="button" class="xnb-btn" id="xnb-close">关闭</button></div>' +
            '<div id="xnb-cap" class="xnb-muted" style="margin-bottom:12px">正在读取能力…</div>' +
            '<div class="xnb-grid">' +
              '<section class="xnb-card">' +
                '<h3>机器 Agent</h3>' +
                '<div class="xnb-row"><label>服务器机器</label><select id="xnb-machine" class="xnb-select"></select></div>' +
                '<div class="xnb-row"><label>Agent 模式</label><select id="xnb-agent-driver" class="xnb-select"><option value="xboard-node">Xboard-Node</option><option value="nobrand-hybrid">NoBrand Hybrid</option></select></div>' +
                '<div class="xnb-row"><label>状态</label><div id="xnb-machine-state" class="xnb-muted">-</div></div>' +
                '<div class="xnb-actions"><button type="button" class="xnb-btn xnb-btn-primary" id="xnb-save-machine">保存机器模式</button><button type="button" class="xnb-btn" id="xnb-install-command">生成安装命令</button></div>' +
                '<div id="xnb-machine-msg" class="xnb-status"></div>' +
                '<div id="xnb-command-box" class="xnb-command" style="display:none"><div class="xnb-muted" style="margin-bottom:6px">命令包含 Machine Token，请勿公开。</div><textarea id="xnb-command" class="xnb-textarea" readonly></textarea><div class="xnb-actions"><button type="button" class="xnb-btn" id="xnb-copy-command">复制命令</button></div></div>' +
              '</section>' +
              '<section class="xnb-card">' +
                '<h3>Mieru Runtime</h3>' +
                '<div class="xnb-row"><label>Mieru 节点</label><select id="xnb-node" class="xnb-select"></select></div>' +
                '<div class="xnb-row"><label>绑定机器</label><select id="xnb-node-machine" class="xnb-select"></select></div>' +
                '<div class="xnb-row"><label>Runtime</label><select id="xnb-runtime" class="xnb-select"><option value="native">Native / Xboard-Node</option><option value="nobrand">NoBrand Runtime</option></select></div>' +
                '<div id="xnb-nobrand-fields">' +
                  '<div class="xnb-row"><label>Profile</label><select id="xnb-profile" class="xnb-select"><option value="iplc">IPLC / 专线性能</option><option value="balanced">Balanced</option><option value="stealth">Stealth</option></select></div>' +
                  '<div class="xnb-row"><label>MTU</label><input id="xnb-mtu" class="xnb-input" value="1400" placeholder="1400 / safe / auto"></div>' +
                  '<div class="xnb-row"><label>Handshake</label><select id="xnb-handshake" class="xnb-select"><option value="no-wait">NO_WAIT</option><option value="standard">Standard</option></select></div>' +
                  '<div class="xnb-row"><label>Multiplexing</label><select id="xnb-mux" class="xnb-select"><option value="off">OFF</option><option value="low">LOW</option><option value="middle">MIDDLE</option><option value="high">HIGH</option></select></div>' +
                  '<div class="xnb-row"><label>Display Host</label><input id="xnb-advertise-host" class="xnb-input" placeholder="留空=节点 Host"></div>' +
                  '<div class="xnb-row"><label>Ingress Profile</label><input id="xnb-ingress-profile" class="xnb-input" placeholder="留空=NoBrand 默认入口"></div>' +
                  '<div class="xnb-row"><label>端口策略</label><div class="xnb-check"><input id="xnb-pin-port" type="checkbox"><span class="xnb-muted">固定首用户为节点 server_port</span></div></div>' +
                  '<div class="xnb-row"><label>同步展示入口</label><div class="xnb-check"><input id="xnb-sync-host" type="checkbox" checked><span class="xnb-muted">跟随 Display Host/节点 Host</span></div></div>' +
                '</div>' +
                '<div class="xnb-actions"><button type="button" class="xnb-btn xnb-btn-primary" id="xnb-save-node">保存 Runtime</button></div>' +
                '<div id="xnb-node-msg" class="xnb-status"></div>' +
              '</section>' +
              '<section class="xnb-card">' +
                '<h3>Snell v5 Runtime</h3>' +
                '<div class="xnb-muted" style="margin-bottom:10px">每个 Xboard 用户自动创建独立 Snell 实例 / PSK / 端口；QUIC Proxy 固定关闭。</div>' +
                '<div class="xnb-row"><label>节点名称</label><input id="xnb-snell-name" class="xnb-input" placeholder="例如 NoBrand-Snell-JP"></div>' +
                '<div class="xnb-row"><label>Display Host</label><input id="xnb-snell-host" class="xnb-input" placeholder="客户端连接入口 IP / 域名"></div>' +
                '<div class="xnb-row"><label>Hybrid 机器</label><select id="xnb-snell-machine" class="xnb-select"></select></div>' +
                '<div class="xnb-row"><label>权限组</label><select id="xnb-snell-group" class="xnb-select"></select></div>' +
                '<div class="xnb-row"><label>Ingress Profile</label><input id="xnb-snell-ingress" class="xnb-input" placeholder="留空=NoBrand 默认入口"></div>' +
                '<div class="xnb-actions"><button type="button" class="xnb-btn xnb-btn-primary" id="xnb-create-snell">创建 Snell v5</button></div>' +
                '<div id="xnb-snell-msg" class="xnb-status"></div>' +
                '<div class="xnb-command"><div class="xnb-row"><label>已有 Snell</label><select id="xnb-snell-existing" class="xnb-select"></select></div>' +
                '<div class="xnb-actions"><button type="button" class="xnb-btn xnb-btn-warn" id="xnb-drop-snell">删除所选逻辑节点</button></div></div>' +
              '</section>' +
              '<section class="xnb-card">' +
                '<h3>Hysteria2 Multi-Auth</h3>' +
                '<div class="xnb-muted" style="margin-bottom:10px">一台机器一个 UDP listener；每个 Xboard 用户使用独立 Auth。NoBrand 负责 Runtime/TLS/Salamander，Companion 只覆盖 clients[]。</div>' +
                '<div class="xnb-row"><label>节点名称</label><input id="xnb-hy2-name" class="xnb-input" placeholder="例如 NoBrand-HY2-JP"></div>' +
                '<div class="xnb-row"><label>Display Host</label><input id="xnb-hy2-host" class="xnb-input" placeholder="客户端连接入口 IP / 域名"></div>' +
                '<div class="xnb-row"><label>UDP 端口</label><input id="xnb-hy2-port" class="xnb-input" type="number" min="1" max="65535" value="23037"></div>' +
                '<div class="xnb-row"><label>SNI</label><input id="xnb-hy2-sni" class="xnb-input" placeholder="例如 www.nvidia.com"></div>' +
                '<div class="xnb-row"><label>Hybrid 机器</label><select id="xnb-hy2-machine" class="xnb-select"></select></div>' +
                '<div class="xnb-row"><label>权限组</label><select id="xnb-hy2-group" class="xnb-select"></select></div>' +
                '<div class="xnb-row"><label>Ingress Profile</label><input id="xnb-hy2-ingress" class="xnb-input" placeholder="留空=NoBrand 默认入口"></div>' +
                '<div class="xnb-actions"><button type="button" class="xnb-btn xnb-btn-primary" id="xnb-create-hy2">创建 Hysteria2</button></div>' +
                '<div id="xnb-hy2-msg" class="xnb-status"></div>' +
                '<div class="xnb-command"><div class="xnb-row"><label>已有 HY2</label><select id="xnb-hy2-existing" class="xnb-select"></select></div>' +
                '<div class="xnb-actions"><button type="button" class="xnb-btn xnb-btn-warn" id="xnb-drop-hy2">删除所选逻辑节点</button></div></div>' +
              '</section>' +
            '</div>' +
          '</div>';

        document.body.append(button, overlay);

        let machines = [];
        let nodes = [];
        let snellNodes = [];
        let hy2Nodes = [];
        let groups = [];
        let capabilities = null;

        const machineSelect = overlay.querySelector('#xnb-machine');
        const nodeSelect = overlay.querySelector('#xnb-node');
        const nodeMachineSelect = overlay.querySelector('#xnb-node-machine');
        const driverSelect = overlay.querySelector('#xnb-agent-driver');
        const machineSnellMeterSelect = overlay.querySelector('#xnb-machine-snell-meter');
        const runtimeSelect = overlay.querySelector('#xnb-runtime');
        const machineMsg = overlay.querySelector('#xnb-machine-msg');
        const nodeMsg = overlay.querySelector('#xnb-node-msg');
        const fields = overlay.querySelector('#xnb-nobrand-fields');
        const snellMachineSelect = overlay.querySelector('#xnb-snell-machine');
        const snellGroupSelect = overlay.querySelector('#xnb-snell-group');
        const snellExistingSelect = overlay.querySelector('#xnb-snell-existing');
        const snellMsg = overlay.querySelector('#xnb-snell-msg');
        const hy2MachineSelect = overlay.querySelector('#xnb-hy2-machine');
        const hy2GroupSelect = overlay.querySelector('#xnb-hy2-group');
        const hy2ExistingSelect = overlay.querySelector('#xnb-hy2-existing');
        const hy2Msg = overlay.querySelector('#xnb-hy2-msg');

        const setMsg = (element, message, isError = false) => {
          element.textContent = message || '';
          element.className = 'xnb-status ' + (isError ? 'xnb-error' : (message ? 'xnb-ok' : ''));
        };

        const selectedMachine = () => machines.find((item) => String(item.id) === machineSelect.value);
        const selectedNode = () => nodes.find((item) => String(item.id) === nodeSelect.value);

        const setSelectOptions = (select, rows, labeler, includeEmpty = false) => {
          const current = select.value;
          select.replaceChildren();
          if (includeEmpty) {
            const empty = document.createElement('option');
            empty.value = '';
            empty.textContent = '未绑定';
            select.appendChild(empty);
          }
          rows.forEach((item) => {
            const option = document.createElement('option');
            option.value = String(item.id);
            option.textContent = labeler(item);
            select.appendChild(option);
          });
          if (Array.from(select.options).some((option) => option.value === current)) select.value = current;
        };

        const renderMachine = () => {
          const item = selectedMachine();
          if (!item) {
            driverSelect.value = 'xboard-node';
            overlay.querySelector('#xnb-machine-state').textContent = '暂无机器';
            return;
          }

          driverSelect.value = item.agent_driver || 'xboard-node';
          const agentSettings = item.agent_settings && typeof item.agent_settings === 'object'
            ? item.agent_settings
            : {};
          machineSnellMeterSelect.value = agentSettings.snell_meter || 'off';
          machineSnellMeterSelect.disabled = driverSelect.value !== 'nobrand-hybrid';

          const now = Date.now() / 1000;
          const online = item.last_seen_at && (now - Number(item.last_seen_at) < 180);
          const isHybrid = (item.agent_driver || 'xboard-node') === 'nobrand-hybrid';
          const nbStatus = item.nobrand_status && typeof item.nobrand_status === 'object'
            ? item.nobrand_status
            : {};
          const nbOnline = isHybrid && item.nobrand_last_seen_at
            && (now - Number(item.nobrand_last_seen_at) < 120);
          const nbState = nbStatus.state || 'unknown';

          let html =
            '<span class="xnb-badge">' + (online ? 'Xboard-Node 在线' : 'Xboard-Node 离线') + '</span>' +
            '<span class="xnb-badge">' + escapeHtml(item.agent_driver || 'xboard-node') + '</span>' +
            '<span class="xnb-muted">节点 ' + Number(item.servers_count || 0) + '</span>';

          if (isHybrid) {
            html += '<div style="margin-top:7px">' +
              '<span class="xnb-badge">' + (nbOnline ? 'Companion 在线' : 'Companion 离线') + '</span>' +
              '<span class="xnb-badge">' + escapeHtml(nbState === 'ok' ? '对账 OK' : nbState === 'error' ? '对账 Error' : '未上报') + '</span>' +
              '<span class="xnb-muted">托管用户 ' + Number(nbStatus.managed_users || 0) +
              ' · Binding ' + Number(nbStatus.bindings || 0) +
              ' · Mieru Traffic ' + Number(nbStatus.traffic_readings || 0) +
              ' · Snell Meter ' + escapeHtml(nbStatus.snell_meter_mode || 'off') +
              '/' + Number(nbStatus.snell_meter_readings || 0) +
              ' · ' + Number(nbStatus.reconcile_ms || 0) + ' ms</span></div>';

            if (nbState === 'error' && nbStatus.message) {
              html += '<div class="xnb-error" style="margin-top:6px">' +
                escapeHtml(String(nbStatus.message)) + '</div>';
            }
          }

          overlay.querySelector('#xnb-machine-state').innerHTML = html;
        };

        const normalizeSettings = (item) => {
          const value = item?.runtime_driver_settings;
          return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
        };

        const renderNode = () => {
          const item = selectedNode();
          const settings = normalizeSettings(item);
          if (!item) {
            runtimeSelect.value = 'native';
            fields.style.display = 'none';
            return;
          }
          nodeMachineSelect.value = item.machine_id ? String(item.machine_id) : '';
          runtimeSelect.value = item.runtime_driver || 'native';
          overlay.querySelector('#xnb-profile').value = settings.profile || 'iplc';
          overlay.querySelector('#xnb-mtu').value = settings.mtu ?? 1400;
          overlay.querySelector('#xnb-handshake').value = settings.handshake_mode || 'no-wait';
          overlay.querySelector('#xnb-mux').value = settings.multiplexing || 'off';
          overlay.querySelector('#xnb-advertise-host').value = settings.advertise_host || '';
          overlay.querySelector('#xnb-ingress-profile').value = settings.ingress_profile || '';
          overlay.querySelector('#xnb-pin-port').checked = Boolean(settings.pin_primary_port);
          overlay.querySelector('#xnb-sync-host').checked = settings.sync_advertise_host !== false;
          fields.style.display = runtimeSelect.value === 'nobrand' ? '' : 'none';
        };

        const load = async () => {
          setMsg(machineMsg, '');
          setMsg(nodeMsg, '');
          try {
            const [machineData, nodeData, capData, groupData] = await Promise.all([
              noBrandAdminApi('server/machine/fetch'),
              noBrandAdminApi('server/manage/getNodes'),
              noBrandAdminApi('server/machine/nobrandCapabilities'),
              noBrandAdminApi('server/group/fetch')
            ]);
            machines = Array.isArray(machineData) ? machineData : (machineData?.data || []);
            const allNodes = Array.isArray(nodeData) ? nodeData : (nodeData?.data || []);
            nodes = allNodes.filter((item) => item.type === 'mieru');
            snellNodes = allNodes.filter((item) => item.type === 'snell');
            hy2Nodes = allNodes.filter((item) => item.type === 'hysteria' && item.runtime_driver === 'nobrand');
            groups = Array.isArray(groupData) ? groupData : (groupData?.data || []);
            capabilities = capData || {};

            setSelectOptions(machineSelect, machines, (item) => item.name + ' (#' + item.id + ')');
            setSelectOptions(nodeMachineSelect, machines, (item) => item.name + ' (#' + item.id + ')', true);
            setSelectOptions(nodeSelect, nodes, (item) => item.name + ' (#' + item.id + ')');
            setSelectOptions(
              snellMachineSelect,
              machines.filter((item) => (item.agent_driver || 'xboard-node') === 'nobrand-hybrid'),
              (item) => item.name + ' (#' + item.id + ')'
            );
            setSelectOptions(snellGroupSelect, groups, (item) => item.name + ' (#' + item.id + ')');
            setSelectOptions(snellExistingSelect, snellNodes, (item) => item.name + ' (#' + item.id + ')');
            setSelectOptions(
              hy2MachineSelect,
              machines.filter((item) => (item.agent_driver || 'xboard-node') === 'nobrand-hybrid'),
              (item) => item.name + ' (#' + item.id + ')'
            );
            setSelectOptions(hy2GroupSelect, groups, (item) => item.name + ' (#' + item.id + ')');
            setSelectOptions(hy2ExistingSelect, hy2Nodes, (item) => item.name + ' (#' + item.id + ')');

            const cap = overlay.querySelector('#xnb-cap');
            cap.innerHTML =
              '<span class="xnb-badge">Phase ' + escapeHtml(capabilities.phase ?? 5) + '</span>' +
              '<span class="xnb-badge">NoBrand ' + escapeHtml(capabilities.version || 'v3.2.2') + '</span>' +
              '<span class="xnb-badge">Companion ' + escapeHtml(capabilities.companion_version || '0.5.0') + '</span>' +
              '<span class="xnb-muted">自动 Runtime：Mieru / Snell v5 / Hysteria2 Multi-Auth</span>';

            renderMachine();
            renderNode();
          } catch (error) {
            setMsg(machineMsg, error?.message || '加载失败', true);
          }
        };

        machineSelect.onchange = renderMachine;
        driverSelect.onchange = () => {
          machineSnellMeterSelect.disabled = driverSelect.value !== 'nobrand-hybrid';
        };
        nodeSelect.onchange = renderNode;
        runtimeSelect.onchange = () => {
          fields.style.display = runtimeSelect.value === 'nobrand' ? '' : 'none';
        };

        overlay.querySelector('#xnb-save-machine').onclick = async () => {
          const item = selectedMachine();
          if (!item) return setMsg(machineMsg, '请选择机器', true);
          setMsg(machineMsg, '正在保存…');
          try {
            const nextAgentSettings = {
              ...(item.agent_settings && typeof item.agent_settings === 'object' ? item.agent_settings : {}),
              snell_meter: machineSnellMeterSelect.value || 'off'
            };
            await noBrandAdminApi('server/machine/save', {
              method: 'POST',
              body: JSON.stringify({
                id: item.id,
                name: item.name,
                notes: item.notes ?? null,
                is_active: item.is_active !== false,
                agent_driver: driverSelect.value,
                agent_settings: nextAgentSettings
              })
            });
            setMsg(machineMsg, '机器 Agent 模式已保存');
            await load();
          } catch (error) {
            setMsg(machineMsg, error?.message || '保存失败', true);
          }
        };

        overlay.querySelector('#xnb-install-command').onclick = async () => {
          const item = selectedMachine();
          if (!item) return setMsg(machineMsg, '请选择机器', true);
          setMsg(machineMsg, '正在生成安装命令…');
          try {
            const data = await noBrandAdminApi('server/machine/installCommand?id=' + encodeURIComponent(item.id));
            const command = data.command || data.data?.command || '';
            overlay.querySelector('#xnb-command').value = command;
            overlay.querySelector('#xnb-command-box').style.display = command ? '' : 'none';
            setMsg(machineMsg, command ? '安装命令已生成' : '未返回安装命令', !command);
          } catch (error) {
            setMsg(machineMsg, error?.message || '生成失败', true);
          }
        };

        overlay.querySelector('#xnb-copy-command').onclick = async () => {
          const field = overlay.querySelector('#xnb-command');
          if (!field.value) return;
          try {
            await navigator.clipboard.writeText(field.value);
            setMsg(machineMsg, '安装命令已复制');
          } catch (_) {
            field.select();
            document.execCommand('copy');
            setMsg(machineMsg, '安装命令已复制');
          }
        };

        overlay.querySelector('#xnb-save-node').onclick = async () => {
          const item = selectedNode();
          if (!item) return setMsg(nodeMsg, '请选择 Mieru 节点', true);

          const machineId = nodeMachineSelect.value ? Number(nodeMachineSelect.value) : null;
          const runtime = runtimeSelect.value;
          if (runtime === 'nobrand' && !machineId) {
            return setMsg(nodeMsg, 'NoBrand Runtime 必须绑定 Hybrid 机器', true);
          }

          const settings = runtime === 'nobrand' ? {
            profile: overlay.querySelector('#xnb-profile').value,
            mtu: overlay.querySelector('#xnb-mtu').value || '1400',
            handshake_mode: overlay.querySelector('#xnb-handshake').value,
            multiplexing: overlay.querySelector('#xnb-mux').value,
            advertise_host: overlay.querySelector('#xnb-advertise-host').value.trim(),
            ingress_profile: overlay.querySelector('#xnb-ingress-profile').value.trim(),
            pin_primary_port: overlay.querySelector('#xnb-pin-port').checked,
            sync_advertise_host: overlay.querySelector('#xnb-sync-host').checked
          } : {};

          setMsg(nodeMsg, '正在保存…');
          try {
            await noBrandAdminApi('server/manage/update', {
              method: 'POST',
              body: JSON.stringify({
                id: item.id,
                machine_id: machineId,
                runtime_driver: runtime,
                runtime_driver_settings: settings
              })
            });
            setMsg(nodeMsg, runtime === 'nobrand' ? 'NoBrand Mieru Runtime 已保存，Companion 将自动对账' : '已切回 Native Runtime');
            await load();
          } catch (error) {
            setMsg(nodeMsg, error?.message || '保存失败', true);
          }
        };

        overlay.querySelector('#xnb-create-snell').onclick = async () => {
          const name = overlay.querySelector('#xnb-snell-name').value.trim();
          const host = overlay.querySelector('#xnb-snell-host').value.trim();
          const machineId = Number(snellMachineSelect.value || 0);
          const groupId = Number(snellGroupSelect.value || 0);
          const ingress = overlay.querySelector('#xnb-snell-ingress').value.trim();

          if (!name || !host || !machineId || !groupId) {
            return setMsg(snellMsg, '请填写节点名、Display Host，并选择 Hybrid 机器和权限组', true);
          }

          setMsg(snellMsg, '正在创建…');
          try {
            const result = await noBrandAdminApi('server/manage/createNoBrandSnell', {
              method: 'POST',
              body: JSON.stringify({
                name,
                host,
                machine_id: machineId,
                group_ids: [groupId],
                ingress_profile: ingress || null,
                advertise_host: host,
                rate: 1
              })
            });
            setMsg(snellMsg, 'Snell v5 逻辑节点已创建 #' + (result.id || ''));
            overlay.querySelector('#xnb-snell-name').value = '';
            await load();
          } catch (error) {
            setMsg(snellMsg, error?.message || '创建失败', true);
          }
        };

        overlay.querySelector('#xnb-drop-snell').onclick = async () => {
          const id = Number(snellExistingSelect.value || 0);
          const item = snellNodes.find((node) => Number(node.id) === id);
          if (!item) return setMsg(snellMsg, '请选择要删除的 Snell 逻辑节点', true);
          if (!window.confirm('删除 ' + item.name + '？Companion 下一轮会移除该节点名下所有 xbn 专属实例。')) return;

          setMsg(snellMsg, '正在删除…');
          try {
            await noBrandAdminApi('server/manage/drop', {
              method: 'POST',
              body: JSON.stringify({ id })
            });
            setMsg(snellMsg, 'Snell 逻辑节点已删除；Companion 将清理专属实例');
            await load();
          } catch (error) {
            setMsg(snellMsg, error?.message || '删除失败', true);
          }
        };

        overlay.querySelector('#xnb-create-hy2').onclick = async () => {
          const name = overlay.querySelector('#xnb-hy2-name').value.trim();
          const host = overlay.querySelector('#xnb-hy2-host').value.trim();
          const port = Number(overlay.querySelector('#xnb-hy2-port').value || 0);
          const sni = overlay.querySelector('#xnb-hy2-sni').value.trim();
          const machineId = Number(hy2MachineSelect.value || 0);
          const groupId = Number(hy2GroupSelect.value || 0);
          const ingress = overlay.querySelector('#xnb-hy2-ingress').value.trim();

          if (!name || !host || !sni || !machineId || !groupId || port < 1 || port > 65535) {
            return setMsg(hy2Msg, '请填写节点名、Display Host、UDP 端口、SNI，并选择 Hybrid 机器和权限组', true);
          }

          setMsg(hy2Msg, '正在创建…');
          try {
            const result = await noBrandAdminApi('server/manage/createNoBrandHy2', {
              method: 'POST',
              body: JSON.stringify({
                name,
                host,
                server_port: port,
                sni,
                machine_id: machineId,
                group_ids: [groupId],
                ingress_profile: ingress || null,
                rate: 1
              })
            });
            setMsg(hy2Msg, 'Hysteria2 Multi-Auth 逻辑节点已创建 #' + (result.id || ''));
            overlay.querySelector('#xnb-hy2-name').value = '';
            await load();
          } catch (error) {
            setMsg(hy2Msg, error?.message || '创建失败', true);
          }
        };

        overlay.querySelector('#xnb-drop-hy2').onclick = async () => {
          const id = Number(hy2ExistingSelect.value || 0);
          const item = hy2Nodes.find((node) => Number(node.id) === id);
          if (!item) return setMsg(hy2Msg, '请选择要删除的 Hysteria2 逻辑节点', true);
          if (!window.confirm('删除 ' + item.name + '？如果该 Runtime 由 Xboard Companion 创建，下一轮会安全移除对应 NoBrand HY2 Runtime。')) return;

          setMsg(hy2Msg, '正在删除…');
          try {
            await noBrandAdminApi('server/manage/drop', {
              method: 'POST',
              body: JSON.stringify({ id })
            });
            setMsg(hy2Msg, 'Hysteria2 逻辑节点已删除；Companion 将按 ownership marker 清理 Runtime');
            await load();
          } catch (error) {
            setMsg(hy2Msg, error?.message || '删除失败', true);
          }
        };

        button.onclick = () => {
          overlay.style.display = 'flex';
          load();
        };
        overlay.querySelector('#xnb-close').onclick = () => overlay.style.display = 'none';
        overlay.addEventListener('click', (event) => {
          if (event.target === overlay) overlay.style.display = 'none';
        });
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
          ensureLiteNavigation();
          ensureLiteDashboard();
          ensureNoBrandManager();
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
