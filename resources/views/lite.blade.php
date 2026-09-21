<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <title>{{ $title }}</title>
  <link rel="stylesheet" href="/lite/app.css?v={{ $version }}">
</head>
<body>
  <div id="app">
    <div class="boot">正在加载 Xboard Lite…</div>
  </div>
  @php
    $liteConfig = [
      'title' => $title,
      'version' => $version,
      'securePath' => $secure_path,
      'logo' => $logo,
    ];
  @endphp
  <script>
    window.XBOARD_LITE = @json($liteConfig);
  </script>
  <script src="/lite/qrcode.min.js?v={{ $version }}" defer></script>
  <script src="/lite/app.js?v={{ $version }}" defer></script>
</body>
</html>
