<?php
/**
 * Smoke test — run INSIDE the roundcubemail container:
 *   docker exec roundcubemail php /var/www/html/plugins/roundcube_iconizer/tests/smoke.php
 *
 * Black-box: logs in through the bundled autologon plugin (localhost only),
 * then asserts what the browser would see. No plugin internals touched.
 */

$base = 'http://127.0.0.1';
$jar  = '/tmp/iconizer-smoke-cookies.txt';
@unlink($jar);

function fetch(string $url, ?string $post = null, string $jar = ''): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    if ($jar !== '') {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$code, $body];
}

$ok = true;

// 1. Login page: token + our assets already present pre-auth.
[$code, $login] = fetch("{$base}/?_task=login", null, $jar);
$token = '';
if (preg_match('/request_token":"([a-zA-Z0-9]+)"/', $login, $m)) {
    $token = $m[1];
}

// 2. Authenticate via autologon (accepts any credentials from localhost).
[$code, $after] = fetch(
    "{$base}/?_task=login&_autologin=1",
    http_build_query(['_task' => 'login', '_action' => 'login', '_token' => $token, '_user' => 'me', '_pass' => 'x']),
    $jar
);
$logged_in = stripos($after, 'task-mail') !== false || str_contains($after, '"iconizer_icons":{');

// 3. Authenticated mail page: the env map must be there, with Administration.
[$code, $mail] = fetch("{$base}/?_task=mail&_mbox=INBOX", null, $jar);

// 4. Folder edit form (settings): the Icon field with picker button + hidden input.
[$code, $form] = fetch("{$base}/?_task=settings&action=edit-folder&_mbox=Administration", null, $jar);

$checks = [
    'login page served'          => $code === 200 || $logged_in,
    'request token extracted'    => $token !== '',
    'autologon succeeded'        => $logged_in,
    'iconify loader loaded'      => str_contains($mail, 'code.iconify.design'),
    'painter js loaded'          => str_contains($mail, 'iconizer-holder') || str_contains($mail, 'plugins/roundcube_iconizer/iconizer.js'),
    'painter css loaded'         => str_contains($mail, 'plugins/roundcube_iconizer/styles/iconizer.css'),
    'icon map published'         => str_contains($mail, '"iconizer_icons"'),
    'Administration mapped'      => str_contains($mail, 'Administration') && str_contains($mail, 'tabler:briefcase'),
    'icon field in folder form'  => str_contains($form, 'id="iconizer-picker-button"') && str_contains($form, '_iconizer_icon'),
    'picker runtime inlined'     => str_contains($form, 'iconizer-overlay') || str_contains($form, 'iconify.design/search'),
    'js asset 200'              => trim((string) shell_exec("curl -s -o /dev/null -w '%{http_code}' --max-time 10 \"{$base}/static.php/plugins/roundcube_iconizer/iconizer.js\"")) === '200',
    'css asset 200'             => trim((string) shell_exec("curl -s -o /dev/null -w '%{http_code}' --max-time 10 \"{$base}/static.php/plugins/roundcube_iconizer/styles/iconizer.css\"")) === '200',
];

foreach ($checks as $name => $passed) {
    echo ($passed ? '  ok   ' : '  FAIL ') . $name . "\n";
    $ok = $ok && $passed;
}

@unlink($jar);
echo $ok ? "SMOKE PASS\n" : "SMOKE FAIL\n";
exit($ok ? 0 : 1);
