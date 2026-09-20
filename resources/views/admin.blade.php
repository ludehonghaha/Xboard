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

      let scheduled = false;
      const clean = () => {
        if (scheduled) return;
        scheduled = true;
        requestAnimationFrame(() => {
          scheduled = false;
          hideExactMenuItems();
          hideRemovedPanels();
          hideRemovedColumns();
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
