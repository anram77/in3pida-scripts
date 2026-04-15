<?php
declare(strict_types=1);

/*
 * Versione semplice, senza blocchi preventivi:
 * - La chiamata CURL parte sempre.
 * - Ogni errore viene scritto in hd-report.txt.
 * - A fine esecuzione si scrive sempre la risposta dell’endpoint.
 */

date_default_timezone_set('Europe/Rome');

$logFile = __DIR__ . DIRECTORY_SEPARATOR . 'hd-report.txt';
function logLine(string $phase, array $data = []): void {
    $row = '[' . date(DATE_ATOM) . '] ' . json_encode(['phase' => $phase] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @file_put_contents($GLOBALS['logFile'], $row . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// --- Lettura config (senza interrompere in caso di errore) ---
$token = '';
$hotelsId = [];
try {
    $cfg = __DIR__ . DIRECTORY_SEPARATOR . 'hd-cfg.txt';
    if (!is_readable($cfg)) {
        logLine('config_error', ['message' => 'hd-cfg.txt non leggibile']);
    } else {
        $lines = @file($cfg, FILE_IGNORE_NEW_LINES);
        if ($lines === false || count($lines) < 2) {
            logLine('config_error', ['message' => 'Formato config non valido', 'lines' => $lines]);
        } else {
            $token = trim((string)$lines[0]);
            $hid = (int)trim((string)$lines[1]);
            if ($token === '' || $hid <= 0) {
                logLine('config_error', ['message' => 'Token o HotelsId non valido', 'token_len' => strlen($token), 'hotelId' => $hid]);
            } else {
                $hotelsId = [$hid];
            }
        }
    }
} catch (Throwable $e) {
    logLine('config_exception', ['type' => get_class($e), 'message' => $e->getMessage()]);
}

// --- Endpoint ---
$version    = '1';
$controller = 'request';
$action     = 'add';
$url        = 'https://hub.hoteldoor.it/api/v' . $version . '/' . $controller . '/' . $action;

// --- Conversione date (come l’originale) ---
function ChangeDateFormat($date) {
    $date = trim((string)$date);
    if (!preg_match('#^\d{2}/\d{2}/\d{4}$#', $date)) {
        return null;
    }
    return substr($date,6,4) . '-' . substr($date,3,2) . '-' . substr($date,0,2);
}

// --- Input ---
$data     = $_POST;
$lang     = isset($_GET['lang']) ? $_GET['lang'] : 'it';
$source   = isset($_GET['source']) ? $_GET['source'] : null;
$campaign = isset($_GET['campaign']) ? $_GET['campaign'] : null;

// --- Timestamp come prima ---
$now = date(DateTime::ISO8601);
$now = substr($now, 0, strlen($now)-8);

// --- Date (nessun blocco) ---
$checkin  = ChangeDateFormat(isset($data['Arrivo'])   ? $data['Arrivo']   : '');
$checkout = ChangeDateFormat(isset($data['Partenza']) ? $data['Partenza'] : '');

// --- Referer e UTM ---
$requestreferer = 'https://' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
$sorgente = '';
$medium   = '';
$utm      = '';
if (!empty($source)) {
    switch ($source) {
        case 'fb':      $sorgente = 'facebook';           $medium = 'social';  break;
        case 'ads':     $sorgente = 'google-adwords';     $medium = 'ppc';     break;
        case 'nl':      $sorgente = 'newsletter';         $medium = 'email';   break;
        case 'rmk':     $sorgente = 'google-remarketing'; $medium = 'ppc';     break;
        case 'web':     $sorgente = 'Sito';               $medium = 'organic'; break;
        case 'landing': $sorgente = 'landing';            $medium = 'landing'; break;
    }
    if (!empty($campaign) && $sorgente !== '' && $medium !== '') {
        $utm = '/' . rawurlencode($campaign)
             . '?utm_source='   . rawurlencode($sorgente)
             . '&utm_medium='   . rawurlencode($medium)
             . '&utm_campaign=' . rawurlencode($campaign);
    }
}
$requestreferer .= $utm;

// --- Nome/Cognome ---
$name = isset($data['Nome_e_Cognome']) ? trim(preg_replace('/\s+/', ' ', $data['Nome_e_Cognome'])) : '';
$nome = $name;
$cognome = '';
if (strpos($name, ' ') !== false) {
    [$nome, $cognome] = explode(' ', $name, 2);
}

// --- Newsletter ---
$newsletter = (isset($data['newsletter']) && (string)$data['newsletter'] === '1');

// --- Bambini ---
$children = [];
$bambiniCount = isset($data['Bambini']) ? (int)$data['Bambini'] : 0;
if ($bambiniCount > 0) {
    for ($i = 1; $i <= $bambiniCount; $i++) {
        $k = 'eta' . $i;
        if (isset($data[$k]) && $data[$k] !== '') {
            $age = (int)$data[$k];
            if ($age >= 0 && $age <= 17) {
                $children[] = $age;
            }
        }
    }
}

// --- Adulti ---
$adulti = isset($data['Adulti']) ? (int)$data['Adulti'] : 2;
$adulti = max(1, $adulti);

// --- Payload ---
$parameters = [
    'StayRequest' => [
        'Key'              => null,
        'ReceivedDateTime' => $now,
        'CheckIn'          => $checkin,
        'CheckOut'         => $checkout,
        'HotelsId'         => $hotelsId,
        'GuestNotes'       => (($sorgente!=='') ? '(Sorgente: ' . $sorgente . ') - ' : '') . (isset($data['Note']) ? $data['Note'] : ''),
        'RequestReferer'   => $requestreferer,
        'RoomsDetails'     => [[
            'BoardBasis' => isset($data['Trattamento']) ? $data['Trattamento'] : '',
            'Occupancy'  => [
                'AdultsCount'       => $adulti,
                'ChildrenAgeArray'  => $children
            ]
        ]]
    ],
    'Contact' => [
        'Key'                    => null,
        'Email'                  => isset($data['E-mail']) ? $data['E-mail'] : '',
        'Language'               => $lang,
        'FirstName'              => $nome,
        'LastName'               => $cognome,
        'MobilePhone'            => isset($data['Telefono']) ? $data['Telefono'] : '',
        'NewsletterSubscription' => $newsletter
    ]
];

// --- JSON ---
$json_data = json_encode($parameters);
if ($json_data === false) {
    logLine('json_encode_error', ['json_error' => json_last_error_msg(), 'parameters_sample' => substr(print_r($parameters, true), 0, 5000)]);
    // Fallback minimale per far partire comunque la chiamata
    $json_data = '{}';
}

// --- Headers ---
$headers = [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $token
];

// --- CURL (parte SEMPRE) ---
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);

$response = curl_exec($ch);
if ($response === false) {
    $err = curl_error($ch);
    $errno = curl_errno($ch);
    logLine('curl_error', ['errno' => $errno, 'error' => $err, 'endpoint' => $url]);
    curl_close($ch);
    http_response_code(502);
    echo 'Upstream error: ' . $err;
    exit;
}

$header_size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$status_code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$header = substr($response, 0, $header_size);
$body   = substr($response, $header_size);
curl_close($ch);

// --- Log risposta SEMPRE ---
logLine('response', [
    'status_code' => $status_code,
    'endpoint'    => $url,
    'body'        => $body !== '' ? $body : '(empty)'
]);

// --- Gestione HTTP non-2xx (senza blocchi pre-call: siamo già post-call) ---
if ($status_code < 200 || $status_code >= 300) {
    $msg = "Upstream HTTP $status_code";
    $decoded = json_decode($body, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        if (!empty($decoded['message']))      $msg .= " – " . $decoded['message'];
        elseif (!empty($decoded['error']))     $msg .= " – " . $decoded['error'];
    }
    logLine('http_error', ['message' => $msg]);
    http_response_code(502);
    echo $msg;
    exit;
}

// --- Successo ---
header('Content-Type: application/json; charset=utf-8');
echo $body;
