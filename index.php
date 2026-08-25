<?php

declare(strict_types=1);

/**
 * Not the application. A diagnosis for the one hosting mistake that produces a bare 404.
 *
 * WHY THIS FILE EXISTS
 *
 * LRMS is served from `public/`. Everything else — `config/` with the database password,
 * `storage/` with the photographs — sits beside it and must never be reachable over the web.
 * So the document root has to be `public_html/public`, not `public_html`.
 *
 * When it is left on `public_html`, every URL returns 404 and the 404 says nothing about why.
 * On Apache the shipped root `.htaccess` forwards requests into `public/` and hides the
 * problem; OpenLiteSpeed — which is what CyberPanel runs — ignores `.htaccess` unless it is
 * explicitly told not to, so there the site is simply dead. That is the report we keep
 * getting, and a 404 gives whoever is standing in front of it nothing to act on.
 *
 * WHAT THIS IS NOT
 *
 * It is deliberately **not** an `index.php` that includes `public/index.php`. That would make
 * the home page work while leaving `config/config.local.php` downloadable to anyone who asks
 * for it — a working site with its database password on the internet, which is worse than a
 * broken one because nobody goes looking for the cause. HOSTING-CYBERPANEL.md warns against
 * exactly that, and this file does not do it: it serves no application code and reads no
 * configuration. It explains the problem and refuses.
 *
 * With a correct document root this file is outside it and can never be requested. On Apache
 * with mod_rewrite present the root `.htaccess` forwards `/` into `public/` before the
 * directory index is considered, so it is not reached either. Being reached at all means
 * something is wrong.
 */

// 503, not 200: the site genuinely is not working, and a search engine should not index this.
http_response_code(503);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$here = __DIR__;

/*
 * Two different mistakes bring somebody here, and they need different fixes.
 *
 * The URL this file was reached at tells them apart. Served from the document root, the path
 * is `/index.php` and the problem is that the document root is one level too high. Served from
 * somewhere below it — `/dhdhdh51-zetpro-c94ffc5/index.php` — the archive was extracted without
 * being flattened, and the folder in that URL is the wrapper it created.
 *
 * The folder name is taken from the URL rather than from the filesystem because that is the
 * one the reader can see, and because a nested layout is not reachable from the document root
 * at all: with the files inside a wrapper there is no `public_html/index.php` to run, so the
 * only way this page is ever seen for that layout is by visiting the folder.
 */
$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
$urlFolder = trim(dirname($scriptName), '/');

$wrapper = $urlFolder !== '' && $urlFolder !== '.' ? $urlFolder : null;

// A wrapper folder is only the explanation if the application really is inside it.
if ($wrapper !== null && !is_file($here . '/public/index.php')) {
    $wrapper = null;
}

$flattened = $wrapper === null && is_file($here . '/public/index.php');

// Last resort: we are at the document root, the application is not beside us, but one folder
// down holds it. Name that folder.
if (!$flattened && $wrapper === null) {
    foreach ((array) glob($here . '/*', GLOB_ONLYDIR) as $candidate) {
        if (is_string($candidate) && is_file($candidate . '/public/index.php')) {
            $wrapper = basename($candidate);

            break;
        }
    }
}

/*
 * Whichever mistake it is, the configuration is sitting inside the document root and is
 * downloadable right now. The URL to it is this page's own folder plus the path, so it can be
 * quoted exactly rather than described — somebody who can see the address can check it.
 *
 * Only listed if the file is really there. Telling somebody their database password is on the
 * internet when it is not would send them chasing the wrong thing.
 */
$prefix = $wrapper !== null ? '/' . $wrapper . '/' : '/';
$exposed = [];

foreach (['config/config.local.php', 'config/config.php'] as $secret) {
    if (is_file($here . '/' . $secret)) {
        $exposed[] = $prefix . $secret;
    }
}

$e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>LRMS is not set up yet</title>
<style>
  :root { color-scheme: light dark; }
  body { margin: 0; padding: 28px 20px 56px; font: 15px/1.6 -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
         background: #f6f7f9; color: #1f2330; }
  main { max-width: 760px; margin: 0 auto; }
  h1 { font-size: 21px; margin: 0 0 6px; }
  h2 { font-size: 16px; margin: 26px 0 8px; }
  p, li { margin: 8px 0; }
  .lead { color: #4a5060; }
  .card { background: #fff; border: 1px solid #e2e5ea; border-radius: 10px; padding: 18px 20px; margin: 18px 0; }
  .danger { background: #fdf2f2; border-color: #f0c2c2; }
  .danger h2 { color: #a11; margin-top: 0; }
  code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 13px; }
  pre { background: #1f2330; color: #eef1f6; padding: 12px 14px; border-radius: 8px; overflow-x: auto; }
  .path { background: #eef1f6; padding: 1px 5px; border-radius: 4px; }
  .muted { color: #6b7280; font-size: 13px; }
  ol { padding-left: 22px; }
</style>
</head>
<body>
<main>

<h1>LRMS is not set up yet</h1>

<?php if ($exposed !== []): ?>
  <div class="card danger">
    <h2>Do this first: your configuration is downloadable right now</h2>
    <p>
      Whatever else is wrong, the application files are inside the part of the server the web
      can reach. Anyone can open these in a browser and read them:
    </p>
    <ul>
      <?php foreach ($exposed as $secret): ?>
        <li><code><?= $e($secret) ?></code></li>
      <?php endforeach; ?>
    </ul>
    <p>
      That is your database password and your application key. Follow the steps below, then
      confirm <code><?= $e($prefix) ?>config/config.php</code> returns 404 or 403. If the site
      has been reachable for any length of time, change the database password as well.
    </p>
  </div>
<?php endif; ?>

<?php if ($flattened): ?>

  <p class="lead">
    The files are in the right place, but the web server is pointed one level too high.
  </p>

  <div class="card">
    <h2>Set the document root to the <code>public</code> folder</h2>
    <p>
      It is on <code class="path">public_html</code> and it needs to be
      <code class="path">public_html/public</code>. LRMS serves only that folder; everything
      else — including the file holding your database password — is meant to sit outside it.
    </p>
    <p><strong>In CyberPanel:</strong></p>
    <ol>
      <li>Websites &rsaquo; List Websites &rsaquo; your domain &rsaquo; <strong>Manage</strong></li>
      <li><strong>vHost Conf</strong></li>
      <li>Change <code>docRoot</code> to <code>$VH_ROOT/public_html/public</code></li>
      <li><strong>Save</strong>, then restart OpenLiteSpeed</li>
    </ol>
    <p class="muted">
      On cPanel or Plesk the same setting is called the document root or web root for the
      domain. On a plain Apache or Nginx server it is <code>DocumentRoot</code> /
      <code>root</code> in the site's config.
    </p>
  </div>

  <div class="card">
    <h2>If you cannot change the document root</h2>
    <p>
      Some shared hosting will not let you. Do <em>not</em> work around it by adding an
      <code>index.php</code> here that loads <code>public/index.php</code> &mdash; the site
      would come up with the files above still downloadable. Use a host that lets you set the
      document root, or point the domain at the <code>public</code> folder some other way.
    </p>
  </div>

<?php else: ?>

  <p class="lead">
    The upload was not flattened, so there is no <code>public_html/public/index.php</code> for
    the web server to reach.
  </p>

  <div class="card">
    <h2>Move the application up one level</h2>
    <?php if ($wrapper !== null): ?>
      <p>
        Everything is inside <code class="path"><?= $e($wrapper) ?></code>. Its contents need to
        sit directly in <code class="path">public_html</code>.
      </p>
      <pre>cd /home/&lt;your-domain&gt;/public_html
mv <?= $e($wrapper) ?>/.[!.]* <?= $e($wrapper) ?>/* .
rmdir <?= $e($wrapper) ?></pre>
    <?php else: ?>
      <p>
        Find the folder the archive created &mdash; GitHub names it after the commit, something
        like <code>dhdhdh51-zetpro-c94ffc5</code> &mdash; and move its contents into
        <code class="path">public_html</code>.
      </p>
      <pre>cd /home/&lt;your-domain&gt;/public_html
mv &lt;folder&gt;/.[!.]* &lt;folder&gt;/* .
rmdir &lt;folder&gt;</pre>
    <?php endif; ?>
    <p>
      The <code>.[!.]*</code> half matters: it carries the hidden <code>.htaccess</code> files.
      A file manager will not copy them unless you turn on &ldquo;show hidden files&rdquo;, and
      without them the clean URLs stop working.
    </p>
    <p>When it is done, <code class="path">public_html</code> should contain:</p>
    <pre>public_html/
├── app/
├── config/
├── public/          &larr; the document root points here
├── storage/
└── .htaccess</pre>
  </div>

  <div class="card">
    <h2>Then set the document root</h2>
    <p>
      CyberPanel &rsaquo; Websites &rsaquo; Manage &rsaquo; vHost Conf, set
      <code>docRoot</code> to <code>$VH_ROOT/public_html/public</code>, save, restart
      OpenLiteSpeed.
    </p>
  </div>

<?php endif; ?>

<?php if (is_file($here . '/public/install.php')): ?>
  <div class="card">
    <h2>If you came here to run the installer</h2>
    <p>
      It lives in the <code>public</code> folder, so
      <code><?= $e($prefix) ?>install.php</code> returns 404 for the same reason the rest of the
      site does. Once the document root is right it is at <code>/install.php</code>.
    </p>
    <p class="muted">
      <code><?= $e($prefix) ?>public/install.php</code> will load right now, but installing
      before the document root is fixed means typing your database password into a site that is
      publishing it. Fix the root first.
    </p>
  </div>
<?php else: ?>
  <div class="card">
    <h2>Looking for the installer?</h2>
    <p>
      There is no <code>public/install.php</code> here. On a site that has already been
      installed that is normal and a 404 on it is correct &mdash; the installer removes itself
      when it succeeds, so that nobody can point the site at a different database afterwards.
    </p>
    <p>
      To apply a newer version, sign in and use <strong>Settings &rsaquo; Update the
      database</strong>. It only ever adds; re-running the installer would drop every table.
    </p>
  </div>
<?php endif; ?>

<div class="card">
  <h2>Check the whole server at once</h2>
  <p>
    <code>deploy/preflight.php</code> tests the PHP version and extensions, the folder
    permissions, the database connection and whether your configuration is exposed. From a
    terminal:
  </p>
  <pre>cd /home/&lt;your-domain&gt;/public_html
php deploy/preflight.php</pre>
  <p class="muted">
    No terminal? Copy that one file into the <code>public</code> folder, open
    <code>/preflight.php</code>, then delete it.
  </p>
</div>

<p class="muted">
  Full walkthrough: <code>HOSTING-CYBERPANEL.md</code>, next to this file.
</p>

</main>
</body>
</html>
