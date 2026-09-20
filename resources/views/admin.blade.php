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
        'TA的邀请', 'Invites'
      ]);

      const hiddenColumnLabels = new Set([
        '佣金', 'Commission'
      ]);

      const hiddenPanelLabels = new Set([
        '今日收入', 'Today Income',
        '月收入', 'Monthly Income',
        '总收入', 'Total Income',
        '总订单', 'Total Orders',
        '待处理工单', 'Pending Tickets',
        '待处理佣金', 'Pending Commission',
        '收入概览', 'Revenue Overview', 'Income Overview'
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
                await navigator.clipboard.writeText(item.code || '');
                setMessage('已复制 ' + (item.code || ''));
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
          hideRemovedColumns();
          ensureAccessInviteButton();
        });
      };

      const observer = new MutationObserver(clean);
      window.addEventListener('DOMContentLoaded', () => {
        clean();
        observer.observe(document.body, { childList: true, subtree: true });
      });
    })();
  </script>

</body>

</html>
