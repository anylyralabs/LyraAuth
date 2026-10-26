<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>@yield('title', 'LyraAuth') · LyraAuth</title>
    <meta name="description" content="@yield('description', 'LyraAuth —— 统一身份认证平台')">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="@asset('app.css')">
</head>
<body class="min-h-screen">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-3 focus:top-3 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-1.5 focus:text-[13px] focus:shadow">跳到主要内容</a>

    @yield('body')

    <script src="@asset('app.js')" defer></script>
</body>
</html>
