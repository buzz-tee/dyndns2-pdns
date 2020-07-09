<?php

include_once('config.inc.php');

function fail($code, $message, $details = NULL) {
    error_log('dyn-update / ' . $message . ': ' . $details);

    http_response_code($code);
    exit($message);
}

function verify_credentials($db, $user, $pass) {
    // generate a password using command
    // htpasswd -bnBC 10 "" 'mypassword' | tr -d ':'
    foreach ($db->query('SELECT `id`,`password` FROM `users` WHERE `active`=1 AND `username`=' . $db->quote($user)) as $row) {
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

function verify_hostname($db, $user_id, $hostname, $zones) {
    foreach($db->query('SELECT `hostname` FROM `permissions` WHERE `user_id`='.$user_id) as $row) {
        if (match_domain($hostname, $row['hostname'])) {
            $hostname_zone = false;
            foreach ($zones as $zone) {
                if (strlen($zone) > strlen($hostname_zone) && substr($hostname, -strlen($zone)) === $zone) {
                    $hostname_zone = $zone;
                }
            }

            return $hostname_zone;
        }
    }
    return false;
}

function build_rrset($hostname, $type, $content) {
    $rrset = array(
        'name' => $hostname,
        'type' => $type,
        'ttl' => DEFAULT_TTL
    );
    if ($content == '') {
        $rrset['changetype'] = 'DELETE';
        $rrset['records'] = array();
    } else {
        if ($type === 'TXT') {
            $content = '"' . addslashes($content) . '"';
        }
        $rrset['changetype'] = 'REPLACE';
        $rrset['records'] = array(
            array(
                'content' => $content,
                'disabled' => FALSE,
                'priority' => 0
            )
        );
    }
    return $rrset;
}


if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
    header('WWW-Authenticate: Basic realm="DynDNS"');
    fail(401, 'Authentication required');
}
$user = $_SERVER['PHP_AUTH_USER'];
$pass = $_SERVER['PHP_AUTH_PW'];

try {
    $db = new PDO(DB_URI, DB_USERNAME, DB_PASSWORD, array(
        \PDO::ATTR_EMULATE_PREPARES => false,
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
    ));
} catch(PDOException $e) {
    fail(500, '911', $e->getMessage());
}

$user_id = verify_credentials($db, $user, $pass);
if (!$user_id) {
    $db = null;
    header('WWW-Authenticate: Basic realm="DynDNS"');
    fail(401, 'badauth');
}

$ch = curl_init(PDNS_ZONES_URL);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Content-Type: application/json',
    'X-API-Key: '.PDNS_API_KEY
));
$response = curl_exec($ch);
$response_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
if ($response_code != 200) {
    $db = null;
    curl_close($ch);
    fail(500, '911', 'Could not retrieve zones: ' . $response);
}
$zones = array();
foreach(json_decode($response, true) as $zone) {
    $zones[] = $zone['id'];
}

$hostname_input = isset($_GET['hostname']) ? $_GET['hostname'] : false;
$hostnames = array();
if ($hostname_input) {
    $hostname_input = explode(',', $hostname_input);
    foreach($hostname_input as $hostname) {
        $extra = '';
        if ($hostname[0] === '_') {
            $extra = '_';
            $hostname = substr($hostname, 1);
        }
        $hostname = filter_var(strtolower($hostname), FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
        if ($hostname) {
            $hostname = $extra . $hostname . '.';
            $zone = verify_hostname($db, $user_id, $hostname, $zones);
            if ($zone) {
                $hostnames[$hostname] = $zone;
            } else {
                $db = null;
                curl_close($ch);
                fail(400, 'nohost', 'Hostname = ' . $hostname . ' is invalid for user');
            }
        }
    }
}
$db = null;
if (empty($hostnames)) {
    curl_close($ch);
    fail(400, 'notfqdn', 'Invalid field hostname = ' . implode(',', $hostname_input));
}
if (count($hostnames) > MAX_UPDATE_HOSTNAMES) {
    curl_close($ch);
    fail(400, 'numhosts', 'Too many hostnames in request (' . count($hostnames) . ' > maximum ' . MAX_UPDATE_HOSTNAMES . ')');
}

if (isset($_GET['myip'])) {
    $myip_input = $_GET['myip'];
    if ($myip_input === '') {
        $ipv4 = '';
        $ipv6 = '';
    } else {
        $myip_input = array_filter(explode(',', $myip_input));
        foreach($myip_input as $myip) {
            $tryip = filter_var($myip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
            if ($tryip !== false) {
                $ipv4 = $tryip;
            }
            $tryip = filter_var($myip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
            if ($tryip !== false) {
                $ipv6 = $tryip;
            }
        }
        if (!isset($ipv4) && !isset($ipv6)) {
            if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
                $myip = $_SERVER['HTTP_CLIENT_IP'];
            } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $myip = $_SERVER['HTTP_X_FORWARDED_FOR'];
            } else {
                $myip = $_SERVER['REMOTE_ADDR'];
            }
            $tryip = filter_var($myip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
            if ($tryip !== false) {
                $ipv4 = $tryip;
            }
            $tryip = filter_var($myip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
            if ($tryip !== false) {
                $ipv6 = $tryip;
            }
        }
    }
    if (!isset($ipv4) && !isset($ipv6)) {
        curl_close($ch);
        fail(500, '911', 'Cannot identify any client IP');
    }
}
if (isset($_GET['txt'])) {
    $txt = $_GET['txt'];
}

if (!isset($ipv4) && !isset($ipv6) && !isset($txt)) {
    curl_close($ch);
    fail(200, 'nochg', 'No change requested: ' . implode(',', $hostnames));
}

$ipv4 = isset($ipv4) ? $ipv4 : false;
$ipv6 = isset($ipv6) ? $ipv6 : false;
$txt = isset($txt) ? $txt : false;

foreach($hostnames as $hostname => $zone) {
    $rrsets = [];
    if ($ipv4 !== false) {
        $rrsets[] = build_rrset($hostname, 'A', $ipv4);
    }
    if ($ipv6 !== false) {
        $rrsets[] = build_rrset($hostname, 'AAAA', $ipv6);
    }
    if ($txt !== false) {
        $rrsets[] = build_rrset($hostname, 'TXT', $txt);
    }

    $payload = json_encode(array('rrsets' => $rrsets));

    curl_setopt($ch, CURLOPT_URL, PDNS_ZONES_URL . '/' . $zone);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    $response = curl_exec($ch);
    $response_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    if ($response_code >= 400) {
        curl_close($ch);
        fail($response_code, 'dnserr', 'PowerDNS API failed: ' . $hostname . '/' . $zone . ' = IPv4 ' . $ipv4 . ', IPv6 ' . $ipv6 . ', TXT ' . $txt . ' => ' . $response);
    }
}

curl_close($ch);
echo "good";
