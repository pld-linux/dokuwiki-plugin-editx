<?php
// Integration regressions for PHP 8.5 and DokuWiki 2026-07-14c.
// Extract an official DokuWiki tarball into a scratch directory, then copy or
// symlink the plugin extracted from the built RPM into its lib/plugins/editx.
// Run as a regular user: php regression.php /path/to/scratch/dokuwiki
// Configuration and all page data are created in a fresh temporary directory.
// Failed fixtures are retained at the printed path for inspection.
if ($argc != 2) { fwrite(STDERR, "Usage: php regression.php DOKUWIKI_ROOT\n"); exit(2); }
define('DOKU_INC', rtrim(realpath($argv[1]), '/') . '/');
$fixture = sys_get_temp_dir() . '/editx-test-' . bin2hex(random_bytes(8));
mkdir($fixture); mkdir($fixture . '/conf'); mkdir($fixture . '/data');
foreach (glob(DOKU_INC . 'conf/*') as $file) {
    if (is_file($file) && !in_array(basename($file), ['local.php', 'local.protected.php', 'acl.auth.php', 'users.auth.php'])) {
        copy($file, $fixture . '/conf/' . basename($file));
    }
}
file_put_contents($fixture . '/conf/local.php', '<?php $conf["savedir"] = ' . var_export($fixture . '/data', true) . '; $conf["useacl"] = 1; $conf["superuser"] = ""; $conf["notify"] = ""; $conf["subscribers"] = 0; $conf["send404"] = 0;');
file_put_contents($fixture . '/conf/users.auth.php', "<?php // test fixture\n");
file_put_contents($fixture . '/conf/acl.auth.php', "*\t@test\t16\nrestricted:*\t@test\t2\nreadonly:*\t@test\t1\n");
foreach (['attic', 'cache', 'index', 'log', 'locks', 'media', 'media_attic', 'media_meta', 'meta', 'pages', 'tmp'] as $dir) mkdir($fixture . '/data/' . $dir);
define('DOKU_CONF', $fixture . '/conf/'); define('NOSESSION', 1); define('DOKU_E_LEVEL', E_ALL);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['SERVER_PORT'] = '80';
$_SERVER['SCRIPT_NAME'] = '/doku.php';
session_id('editx-regression');
require DOKU_INC . 'inc/init.php';
$auth = plugin_load('auth', 'authplain'); $AUTH_ACL = auth_loadACL();
$_SERVER['REMOTE_USER'] = 'tester'; $USERINFO = ['grps' => ['test']];
$ID = 'start'; $REV = ''; $INFO = ['exists' => true];
set_error_handler(function ($level, $message, $file, $line) {
    if (error_reporting() & $level) throw new ErrorException($message, 0, $level, $file, $line);
});
require_once DOKU_PLUGIN . 'editx/action.php';
function expect($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function seed($id, $text = "Original content\n") { saveWikiText($id, $text, 'fixture'); clearstatcache(); }
function request($post = [], $get = []) {
    global $ID, $INPUT, $MSG;
    $_POST = $post; $_GET = $get; $_REQUEST = array_merge($get, $post);
    $INPUT = new dokuwiki\Input\Input(); $MSG = [];
    $ID = $post['oldpage'] ?? $get['oldpage'] ?? 'start';
    $action = 'editx'; $event = new Doku_Event('TPL_ACT_UNKNOWN', $action);
    $plugin = new action_plugin_editx();
    ob_start();
    try { $plugin->_handle_tpl_act($event, null); return ob_get_contents(); }
    finally { ob_end_clean(); }
}
function renameRequest($old, $new, $extra = []) {
    return request($extra + ['work' => 'rename', 'oldpage' => $old, 'newpage' => $new, 'rp_confirm' => '1', 'sectok' => getSecurityToken()]);
}
function deleteRequest($old, $extra = []) {
    return request($extra + ['work' => 'delete', 'oldpage' => $old, 'dp_confirm' => '1', 'sectok' => getSecurityToken()]);
}
$tests = [];
$tests['empty form and hooks'] = function () {
    $plugin = new action_plugin_editx(); $handler = new Doku_Event_Handler(); $plugin->register($handler);
    $html = request(); expect(substr_count($html, 'name="sectok"') == 2, 'both forms need CSRF tokens');
};
$tests['rename with redirect and history'] = function () {
    seed('source'); io_saveFile(wikiFN('source', 1500000000), 'old revision');
    renameRequest('source', 'dest:target');
    expect(rawWiki('dest:target') === "Original content\n", 'target content');
    expect(str_contains(rawWiki('source'), 'dest:target'), 'source redirect');
    expect(io_readFile(wikiFN('dest:target', 1500000000)) === 'old revision', 'history transferred');
};
$tests['rename without redirect'] = function () {
    seed('noredirect'); renameRequest('noredirect', 'renamed', ['rp_nr' => '1']);
    expect(!page_exists('noredirect') && page_exists('renamed'), 'rename without redirect');
};
$tests['delete with and without deletion log'] = function () {
    foreach (['keepdelete' => '0', 'purgedelete' => '1'] as $id => $purge) {
        seed($id); io_saveFile(wikiFN($id, 1500000000), 'history');
        deleteRequest($id, ['dp_purge' => $purge]);
        expect(!page_exists($id), 'page removed'); expect(!file_exists(wikiFN($id, 1500000000)), 'attic removed');
        expect(file_exists(metaFN($id, '.changes')) === ($purge === '0'), 'deletion log policy');
    }
};
$tests['confirmation and optional inputs'] = function () {
    seed('unconfirmed'); renameRequest('unconfirmed', 'unconfirmedtarget', ['rp_confirm' => '']);
    deleteRequest('unconfirmed', ['dp_confirm' => '']);
    expect(page_exists('unconfirmed') && !page_exists('unconfirmedtarget'), 'confirmation required');
    request(['work' => ['rename'], 'summary' => ['invalid']]);
};
$tests['CSRF and GET do not mutate'] = function () {
    seed('csrf');
    foreach (['', 'invalid'] as $token) {
        renameRequest('csrf', 'csrftarget', ['sectok' => $token]); deleteRequest('csrf', ['sectok' => $token]);
    }
    request([], ['work' => 'delete', 'oldpage' => 'csrf', 'dp_confirm' => '1', 'sectok' => getSecurityToken()]);
    expect(page_exists('csrf') && !page_exists('csrftarget'), 'CSRF/GET rejected');
};
$tests['HTML attributes escaped'] = function () {
    seed('xss'); $payload = '\"><script>alert(1)</script>';
    $html = renameRequest('xss', 'target', ['summary' => $payload, 'rp_confirm' => '']);
    expect(!str_contains($html, '<script>') && str_contains($html, hsc($payload)), 'rename summary escaped');
    $html = deleteRequest('xss', ['summary' => $payload, 'dp_confirm' => '']);
    expect(!str_contains($html, '<script>') && str_contains($html, hsc($payload)), 'delete summary escaped');
};
$tests['create and delete ACLs'] = function () {
    seed('acltest'); renameRequest('acltest', 'restricted:target');
    expect(!page_exists('restricted:target') && rawWiki('acltest') === "Original content\n", 'AUTH_CREATE required');
    seed('readonly:page'); deleteRequest('readonly:page');
    expect(page_exists('readonly:page'), 'delete ACL enforced');
};
$tests['locked pages'] = function () {
    seed('locked'); io_saveFile(wikiLockFN('locked'), 'someone-else');
    deleteRequest('locked'); renameRequest('locked', 'lockedtarget');
    expect(page_exists('locked') && !page_exists('lockedtarget'), 'locked source protected');
};
$tests['history names and regex metacharacters'] = function () {
    seed('txt'); io_saveFile(wikiFN('txt', 1500000000), 'history'); renameRequest('txt', 'newname');
    expect(io_readFile(wikiFN('newname', 1500000000)) === 'history', 'extension not renamed');
    seed('a.b'); seed('axb'); io_saveFile(wikiFN('a.b', 1500000000), 'own'); io_saveFile(wikiFN('axb', 1500000000), 'neighbor');
    deleteRequest('a.b'); expect(page_exists('axb') && io_readFile(wikiFN('axb', 1500000000)) === 'neighbor', 'literal page filename matching');
};
$tests['conflicting history aborts rename'] = function () {
    seed('conflict'); io_saveFile(wikiFN('conflict', 1500000000), 'source history'); io_saveFile(wikiFN('occupied', 1500000000), 'target history');
    renameRequest('conflict', 'occupied');
    expect(rawWiki('conflict') === "Original content\n" && !page_exists('occupied'), 'conflict preserves source');
    expect(io_readFile(wikiFN('occupied', 1500000000)) === 'target history', 'conflict preserves target history');
};
$tests['move failure preserves source and history'] = function () {
    global $conf, $MSG;
    seed('movefail'); io_saveFile(wikiFN('movefail', 1500000000), 'history');
    $dir = $conf['olddir'] . '/blocked'; mkdir($dir); chmod($dir, 0500);
    try {
        renameRequest('movefail', 'blocked:target', ['rp_nr' => '1']);
        expect(rawWiki('movefail') === "Original content\n" && !page_exists('blocked:target'), 'failed move preserves source');
        expect(io_readFile(wikiFN('movefail', 1500000000)) === 'history', 'failed move preserves history');
        expect(file_exists(metaFN('movefail', '.changes')), 'earlier metadata move rolled back');
        expect(!array_filter($MSG, fn($msg) => $msg['lvl'] === 1), 'failure must not report success');
    } finally { chmod($dir, 0700); }
};
$tests['cancelled save preserves source and history'] = function () {
    global $EVENT_HANDLER;
    seed('cancelled'); io_saveFile(wikiFN('cancelled', 1500000000), 'history');
    $hook = new class {
        function cancel($event, $param) { if ($event->data['id'] === 'cancelledtarget') $event->preventDefault(); }
    };
    $EVENT_HANDLER->register_hook('COMMON_WIKIPAGE_SAVE', 'BEFORE', $hook, 'cancel');
    renameRequest('cancelled', 'cancelledtarget', ['rp_nr' => '1']);
    expect(rawWiki('cancelled') === "Original content\n" && !page_exists('cancelledtarget'), 'cancelled target save preserves source');
    expect(io_readFile(wikiFN('cancelled', 1500000000)) === 'history', 'cancelled save restores history');
};
$tests['delete failure is reported'] = function () {
    global $conf, $MSG;
    seed('deletefail:page'); $dir = dirname(wikiFN('deletefail:page')); chmod($dir, 0500);
    try {
        deleteRequest('deletefail:page');
        expect(page_exists('deletefail:page'), 'unwritable page retained');
        expect(file_exists(metaFN('deletefail:page', '.changes')), 'history retained on delete failure');
        expect(!array_filter($MSG, fn($msg) => $msg['lvl'] === 1), 'failed delete must not report success');
    } finally { chmod($dir, 0700); }
};
$tests['real revision remains readable after rename'] = function () {
    seed('revision', "First version\n"); $rev = filemtime(wikiFN('revision'));
    while (time() <= $rev) usleep(100000);
    seed('revision', "Second version\n");
    renameRequest('revision', 'revisiontarget', ['rp_nr' => '1']);
    expect(rawWiki('revisiontarget', $rev) === "First version\n", 'DokuWiki reads renamed revision');
    expect(rawWiki('revisiontarget') === "Second version\n", 'latest revision retained');
};
$tests['rename dotted page preserves neighboring history'] = function () {
    seed('c.d'); seed('cxd'); io_saveFile(wikiFN('cxd', 1500000000), 'neighbor');
    renameRequest('c.d', 'dotrenamed', ['rp_nr' => '1']);
    expect(io_readFile(wikiFN('cxd', 1500000000)) === 'neighbor', 'neighbor history must not be moved');
};
$tests['missing and existing page validation'] = function () {
    renameRequest('missing', 'missingtarget'); expect(!page_exists('missingtarget'), 'missing source rejected');
    seed('existingsource'); seed('existingtarget', 'target content');
    renameRequest('existingsource', 'existingtarget');
    expect(rawWiki('existingtarget') === 'target content', 'existing destination preserved');
};
$tests['anonymous membership check'] = function () {
    global $USERINFO;
    $saved = $USERINFO; unset($_SERVER['REMOTE_USER']); $USERINFO = null;
    try { expect(!(new action_plugin_editx())->_auth_check_list('@admin'), 'anonymous user not privileged'); }
    finally { $_SERVER['REMOTE_USER'] = 'tester'; $USERINFO = $saved; }
};
$failures = 0;
foreach ($tests as $name => $test) {
    try { $test(); fwrite(STDOUT, "PASS $name\n"); }
    catch (Throwable $error) { $failures++; fwrite(STDERR, "FAIL $name: $error\n"); }
}
fwrite(STDOUT, count($tests) . " tests, $failures failures; fixture: $fixture\n");
exit($failures ? 1 : 0);
