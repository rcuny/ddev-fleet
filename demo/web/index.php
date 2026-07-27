<?php
// ddev-fleet demo — a generic DDEV `type: php` project the fleet can deploy with
// zero external dependencies. It exists to prove the fleet end-to-end: cloned
// from the bundled local repo, started by DDEV, and served here behind the
// fleet's Caddy TLS proxy. Single file, no database, no framework.
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ddev-fleet demo</title>
<style>
  :root { color-scheme: light dark; }
  body { margin: 0; min-height: 100vh; display: grid; place-items: center;
         font: 16px/1.5 system-ui, sans-serif; background: #0d1117; color: #e6edf3; }
  @media (prefers-color-scheme: light) { body { background: #f6f8fa; color: #1f2328; } }
  main { max-width: 34rem; padding: 2rem; }
  h1 { font-size: 1.6rem; margin: 0 0 .5rem; }
  .ok { color: #2ea043; }
  dl { display: grid; grid-template-columns: auto 1fr; gap: .35rem 1rem; margin: 1.5rem 0; }
  dt { opacity: .7; }
  code { background: rgba(127,127,127,.18); padding: .1rem .35rem; border-radius: 4px; }
  p.small { opacity: .75; font-size: .9rem; }
</style>
</head>
<body>
<main>
  <h1><span class="ok">&#10003;</span> ddev-fleet demo is running</h1>
  <p>A generic DDEV <code>type: php</code> project, deployed and served by
     <strong>ddev-fleet</strong>.</p>
  <dl>
    <dt>Instance</dt><dd><code><?= htmlspecialchars($host, ENT_QUOTES) ?></code></dd>
    <dt>PHP</dt><dd><?= htmlspecialchars(PHP_VERSION, ENT_QUOTES) ?></dd>
    <dt>Path</dt><dd>Caddy (public TLS) &rarr; ddev-router &rarr; this container</dd>
  </dl>
  <p class="small">Add your own projects to <code>fleet.yml</code>. Tear this one
     down any time with <code>fleet destroy demo--main</code>.</p>
</main>
</body>
</html>
