<?php
  
include_once('config.inc.php');

function fail($code, $message) {
    error_log('dyn-update / ' . $message);

    http_response_code($code);
    exit($message);
}

function verify_credentials($db, $user, $pass) {
    // generate a password using command
    // htpasswd -bnBC 10 "" 'mypassword' | tr -d ':'
    foreach ($db->query('SELECT `id`, `password` FROM `users` WHERE `active`=1 AND `username`=' . $db->quote($user)) as $row) {
        if (password_verify($pass, $row['password'])) {
            return $row['id'];
        }
    }
    return false;
}

function match_domain($domain, $pattern) {
    if ($pattern[0] !== '.') {
        return ($domain === $pattern);
    }
    $length = strlen($pattern);
    if ($length == 0) {
        return true;
    }
    return (substr($domain, -$length) === $pattern);
}

function verify_domain($db, $user_id, $domain) {
    foreach($db->query('SELECT `host`,`zone` FROM `permissions` WHERE `user_id`='.$user_id) as $row) {
        if (match_domain($domain, $row['host'])) {
            return $row['zone'];
        }
    }
    return false;
}


if (!isset($_SERVER['PHP_AUTH_USER'])) {
    header('WWW-Authenticate: Basic realm="DynDNS"');
    fail(401, 'Authentication required');
}
$user = $_SERVER['PHP_AUTH_USER'];
$pass = $_SERVER['PHP_AUTH_PW'];

try {
    $db = new PDO('mysql:unix_socket='.MYSQL_SOCKET.';dbname='.MYSQL_DATABASE.';charset=utf8mb4', MYSQL_USERNAME, MYSQL_PASSWORD, array(
        \PDO::ATTR_EMULATE_PREPARES => false,
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
    ));
} catch(PDOException $e) {
    fail(500, $e->getMessage());
}

$user_id = verify_credentials($db, $user, $pass);
if (!$user_id) {
    $db = null;
    header('WWW-Authenticate: Basic realm="DynDNS"');
    fail(401, 'Authentication required');
}

$domain = isset($_GET['domain']) ? $_GET['domain'] : false;
if ($domain) {
    $extra = '';
    if ($domain[0] === '_') {
        $extra = '_';
        $domain = substr($domain, 1);
    }
    $domain = filter_var(strtolower($domain), FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
    if ($domain) {
        $domain = $extra . $domain;
    }
}
if (!$domain) {
    $db = null;
    fail(400, 'Bad host name: ' . (isset($_GET['domain']) ? $_GET['domain'] : 'missing'));
}

$zone = verify_domain($db, $user_id, $domain);
$db = null;
if (!$zone) {
    fail(403, 'Forbidden');
}

if (isset($_GET['ipv4'])) {
    $ipv4 = filter_var($_GET['ipv4'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    if ($ipv4 === FALSE) {
        fail(400, 'Bad IPv4 address: ' . $domain . ' => ' . $_GET['ipv4']);
    }
}
if (isset($_GET['ipv6'])) {
    $ipv6 = filter_var($_GET['ipv6'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
    if ($ipv6 === FALSE) {
        fail(400, 'Bad IPv6 address: ' . $domain . ' => ' . $_GET['ipv6']);
    }
}
if (isset($_GET['txt'])) {
    $txt = $_GET['txt'];
}

if (!isset($ipv4) && !isset($ipv6) && !isset($txt)) {
    fail(304, 'No change requested: ' . $domain);
}

$rrsets = [];
if (isset($ipv4)) {
    $rrsets[] = array(
        'name' => $domain . '.',
        'type' => 'A',
        'ttl' => 60,
        'changetype' => 'REPLACE',
        'records' => array(
            array(
                'content' => $ipv4,
                'disabled' => FALSE,
                'priority' => 0
            )
        )
    );
} else {
    $ipv4 = false;
}
if (isset($ipv6)) {
    $rrsets[] = array(
        'name' => $domain . '.',
        'type' => 'AAAA',
        'ttl' => 60,
        'changetype' => 'REPLACE',
        'records' => array(
            array(
                'content' => $ipv6,
                'disabled' => FALSE,
                'priority' => 0
            )
        )
    );
} else {
    $ipv6 = false;
}
if (isset($txt)) {
    $rrset = array(
        'name' => $domain. '.',
        'type' => 'TXT',
        'ttl' => 60
    );
    if ($txt == '') {
        $rrset['changetype'] = 'DELETE';
        $rrset['records'] = array();
    } else {
        $rrset['changetype'] = 'REPLACE';
        $rrset['records'] = array(
            array(
                'content' => '"' . addslashes($txt) . '"',
                'disabled' => FALSE,
                'priority' => 0
            )
        );
    }
    $rrsets[] = $rrset;
} else {
    $txt = false;
}

$payload = json_encode(array('rrsets' => $rrsets));

$ch = curl_init(PDNS_ZONES_URL . $zone);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Content-Type: application/json',
    'X-API-Key: '.PDNS_API_KEY
));
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
$response = curl_exec($ch);
$response_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($response_code >= 400) {
    fail($response_code, 'PowerDNS API failed: ' . $domain . '/' . $zone . ' = IPv4 ' . $ipv4 . ', IPv6 ' . $ipv6 . ', TXT ' . $txt . ' => ' . $response);
}

echo "OK";


