<?php
declare(strict_types=1);

/*
 * Versione semplice, con supporto multi-hotel opzionale:
 * - Se $multi = false:
 *   - legge token dalla prima riga di hd-cfg.txt
 *   - legge il primo HotelsId dalla seconda riga
 *   - esegue una sola chiamata CURL
 *
 * - Se $multi = true:
 *   - legge token dalla prima riga di hd-cfg.txt
 *   - legge tutti gli HotelsId dalle righe successive
 *   - esegue una chiamata CURL per ogni HotelsId trovato
 *
 * In tutti i casi:
 * - la/e chiamata/e CURL partono comunque;
 * - ogni errore viene scritto in hd-report.txt;
 * - ogni risposta viene loggata;
 * - in modalità single si mantiene l'output originale;
 * - in modalità multi si restituisce un JSON aggregato con l'esito di tutte le chiamate.
 */

date_default_timezone_set('Europe/Rome');

/*
 * Flag globale:
 * - false = comportamento classico single-hotel
 * - true  = modalità multi-hotel
 */
//Defaul a true, se false è identico a hd-insert.php - ! BISOGNEREBBE UNIFICARE I DUE FILE PRIMA O POI
$multi = true;

$logFile = __DIR__ . DIRECTORY_SEPARATOR . 'hd-report.txt';

function logLine(string $phase, array $data = []): void
{
    global $logFile;

    $row = '[' . date(DATE_ATOM) . '] ' . json_encode(
        ['phase' => $phase] + $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    @file_put_contents($logFile, $row . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * Converte una data da dd/mm/YYYY a YYYY-mm-dd.
 * Restituisce null se il formato in ingresso non è valido.
 */
function ChangeDateFormat($date): ?string
{
    $date = trim((string)$date);

    if (!preg_match('#^\d{2}/\d{2}/\d{4}$#', $date)) {
        return null;
    }

    return substr($date, 6, 4) . '-' . substr($date, 3, 2) . '-' . substr($date, 0, 2);
}

/**
 * Legge il file di configurazione hd-cfg.txt.
 *
 * Formato atteso:
 * token
 * hotelId1
 * hotelId2
 * ...
 *
 * Se $multi = false prende solo il primo HotelsId utile dopo il token.
 * Se $multi = true prende tutti gli HotelsId utili dopo il token.
 */
function loadHoteldoorConfig(string $cfgPath, bool $multi): array
{
    $token = '';
    $hotelIds = [];

    try {
        if (!is_readable($cfgPath)) {
            logLine('config_error', ['message' => 'hd-cfg.txt non leggibile']);

            return [
                'token' => $token,
                'hotel_ids' => $hotelIds,
            ];
        }

        $lines = @file($cfgPath, FILE_IGNORE_NEW_LINES);

        if ($lines === false || count($lines) < 2) {
            logLine('config_error', [
                'message' => 'Formato config non valido',
                'lines'   => $lines,
            ]);

            return [
                'token' => $token,
                'hotel_ids' => $hotelIds,
            ];
        }

        $token = trim((string)$lines[0]);

        if ($token === '') {
            logLine('config_error', [
                'message'   => 'Token non valido',
                'token_len' => strlen($token),
            ]);
        }

        if ($multi === false) {
            $rawId = trim((string)$lines[1]);

            if (!preg_match('/^\d+$/', $rawId)) {
                logLine('config_error', [
                    'message' => 'HotelsId non valido in modalità single',
                    'value'   => $rawId,
                    'line'    => 2,
                ]);
            } else {
                $hid = (int)$rawId;

                if ($hid <= 0) {
                    logLine('config_error', [
                        'message' => 'HotelsId non valido in modalità single',
                        'value'   => $rawId,
                        'line'    => 2,
                    ]);
                } else {
                    $hotelIds[] = $hid;
                }
            }
        } else {
            for ($i = 1, $max = count($lines); $i < $max; $i++) {
                $rawId = trim((string)$lines[$i]);

                if ($rawId === '') {
                    continue;
                }

                if (!preg_match('/^\d+$/', $rawId)) {
                    logLine('config_error', [
                        'message' => 'HotelsId non valido in modalità multi',
                        'value'   => $rawId,
                        'line'    => $i + 1,
                    ]);
                    continue;
                }

                $hid = (int)$rawId;

                if ($hid <= 0) {
                    logLine('config_error', [
                        'message' => 'HotelsId non valido in modalità multi',
                        'value'   => $rawId,
                        'line'    => $i + 1,
                    ]);
                    continue;
                }

                $hotelIds[] = $hid;
            }

            if ($hotelIds === []) {
                logLine('config_error', [
                    'message' => 'Nessun HotelsId valido trovato in modalità multi',
                ]);
            }
        }
    } catch (Throwable $e) {
        logLine('config_exception', [
            'type'    => get_class($e),
            'message' => $e->getMessage(),
        ]);
    }

    return [
        'token' => $token,
        'hotel_ids' => $hotelIds,
    ];
}

/**
 * Esegue una chiamata verso Hoteldoor per uno specifico HotelsId.
 * Se $hotelId è null o non valido, il payload viene inviato con HotelsId vuoto.
 */
function performHoteldoorCall(string $url, string $token, array $parameters, ?int $hotelId): array
{
    $payload = $parameters;

    $payload['StayRequest']['HotelsId'] = ($hotelId !== null && $hotelId > 0)
        ? [$hotelId]
        : [];

    $jsonData = json_encode($payload);

    if ($jsonData === false) {
        logLine('json_encode_error', [
            'hotel_id'          => $hotelId,
            'json_error'        => json_last_error_msg(),
            'parameters_sample' => substr(print_r($payload, true), 0, 5000),
        ]);

        $jsonData = '{}';
    }

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);

    $response = curl_exec($ch);

    if ($response === false) {
        $err = curl_error($ch);
        $errno = curl_errno($ch);

        logLine('curl_error', [
            'hotel_id' => $hotelId,
            'errno'    => $errno,
            'error'    => $err,
            'endpoint' => $url,
        ]);

        curl_close($ch);

        return [
            'hotel_id'    => $hotelId,
            'ok'          => false,
            'status_code' => 0,
            'body'        => '',
            'error'       => 'Upstream error: ' . $err,
        ];
    }

    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $body = substr($response, $headerSize);

    curl_close($ch);

    logLine('response', [
        'hotel_id'    => $hotelId,
        'status_code' => $statusCode,
        'endpoint'    => $url,
        'body'        => $body !== '' ? $body : '(empty)',
    ]);

    if ($statusCode < 200 || $statusCode >= 300) {
        $msg = 'Upstream HTTP ' . $statusCode;

        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            if (!empty($decoded['message'])) {
                $msg .= ' – ' . $decoded['message'];
            } elseif (!empty($decoded['error'])) {
                $msg .= ' – ' . $decoded['error'];
            }
        }

        logLine('http_error', [
            'hotel_id' => $hotelId,
            'message'  => $msg,
        ]);

        return [
            'hotel_id'    => $hotelId,
            'ok'          => false,
            'status_code' => $statusCode,
            'body'        => $body,
            'error'       => $msg,
        ];
    }

    return [
        'hotel_id'    => $hotelId,
        'ok'          => true,
        'status_code' => $statusCode,
        'body'        => $body,
        'error'       => null,
    ];
}

// --- Lettura config ---
$config = loadHoteldoorConfig(__DIR__ . DIRECTORY_SEPARATOR . 'hd-cfg.txt', $multi);
$token = $config['token'];
$hotelIds = $config['hotel_ids'];

// --- Endpoint ---
$version    = '1';
$controller = 'request';
$action     = 'add';
$url        = 'https://hub.hoteldoor.it/api/v' . $version . '/' . $controller . '/' . $action;

// --- Input ---
$data     = $_POST;
$lang     = isset($_GET['lang']) ? (string)$_GET['lang'] : 'it';
$source   = isset($_GET['source']) ? (string)$_GET['source'] : null;
$campaign = isset($_GET['campaign']) ? (string)$_GET['campaign'] : null;

// --- Timestamp come prima ---
$now = date(DateTime::ISO8601);
$now = substr($now, 0, strlen($now) - 8);

// --- Date (nessun blocco) ---
$checkin  = ChangeDateFormat($data['Arrivo'] ?? '');
$checkout = ChangeDateFormat($data['Partenza'] ?? '');

// --- Referer e UTM ---
$requestreferer = 'https://' . ($_SERVER['SERVER_NAME'] ?? 'localhost');

$sorgente = '';
$medium   = '';
$utm      = '';

if (!empty($source)) {
    switch ($source) {
        case 'fb':
            $sorgente = 'facebook';
            $medium = 'social';
            break;

        case 'ads':
            $sorgente = 'google-adwords';
            $medium = 'ppc';
            break;

        case 'nl':
            $sorgente = 'newsletter';
            $medium = 'email';
            break;

        case 'rmk':
            $sorgente = 'google-remarketing';
            $medium = 'ppc';
            break;

        case 'web':
            $sorgente = 'Sito';
            $medium = 'organic';
            break;

        case 'landing':
            $sorgente = 'landing';
            $medium = 'landing';
            break;
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
$name = isset($data['Nome_e_Cognome'])
    ? trim((string)preg_replace('/\s+/', ' ', (string)$data['Nome_e_Cognome']))
    : '';

$nome = $name;
$cognome = '';

if (strpos($name, ' ') !== false) {
    [$nome, $cognome] = explode(' ', $name, 2);
}

// --- Newsletter ---
$newsletter = isset($data['newsletter']) && (string)$data['newsletter'] === '1';

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

// --- Payload base ---
$baseParameters = [
    'StayRequest' => [
        'Key'              => null,
        'ReceivedDateTime' => $now,
        'CheckIn'          => $checkin,
        'CheckOut'         => $checkout,
        'HotelsId'         => [],
        'GuestNotes'       => (($sorgente !== '') ? '(Sorgente: ' . $sorgente . ') - ' : '') . ($data['Note'] ?? ''),
        'RequestReferer'   => $requestreferer,
        'RoomsDetails'     => [[
            'BoardBasis' => $data['Trattamento'] ?? '',
            'Occupancy'  => [
                'AdultsCount'      => $adulti,
                'ChildrenAgeArray' => $children,
            ],
        ]],
    ],
    'Contact' => [
        'Key'                    => null,
        'Email'                  => $data['E-mail'] ?? '',
        'Language'               => $lang,
        'FirstName'              => $nome,
        'LastName'               => $cognome,
        'MobilePhone'            => $data['Telefono'] ?? '',
        'NewsletterSubscription' => $newsletter,
    ],
];

// --- Elenco target per le chiamate ---
/*
 * Single:
 * - una sola chiamata
 * - se manca HotelsId valido, parte comunque con HotelsId vuoto
 *
 * Multi:
 * - una chiamata per ogni HotelsId valido trovato
 * - se non ne trova nessuno, parte comunque una chiamata con HotelsId vuoto
 */
$callTargets = [];

if ($multi === true) {
    $callTargets = ($hotelIds !== []) ? $hotelIds : [null];
} else {
    $callTargets = [isset($hotelIds[0]) ? $hotelIds[0] : null];
}

// --- Esecuzione chiamate ---
$results = [];
$hasFailures = false;

foreach ($callTargets as $hotelId) {
    $result = performHoteldoorCall(
        $url,
        $token,
        $baseParameters,
        $hotelId !== null ? (int)$hotelId : null
    );

    $results[] = $result;

    if ($result['ok'] !== true) {
        $hasFailures = true;
    }
}

// --- Output finale ---
if ($multi === false) {
    $result = $results[0];

    if ($result['ok'] !== true) {
        http_response_code(502);
        echo $result['error'] ?? 'Upstream error';
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo $result['body'];
    exit;
}

// --- Output multi-hotel ---
http_response_code($hasFailures ? 502 : 200);
header('Content-Type: application/json; charset=utf-8');

echo json_encode(
    [
        'multi'      => true,
        'all_ok'     => !$hasFailures,
        'calls'      => count($results),
        'results'    => $results,
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
