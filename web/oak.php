<?php
// OakRemote helper: talks to the Tasmota IR blaster for oakremote.html.
// Tasmota 16 drops /cm and /cs requests without a same-host Referer, so they go through here.
// Only IR send, IR learning (log polling) and status are exposed; codes live in codes.json.

// Set these two for your setup.
const TASMOTA = 'http://192.168.1.50';            // the flashed OakRemote
const STORE   = '/var/lib/oakremote/codes.json';   // writable by the web server user

header('Content-Type: application/json');
header('Cache-Control: no-store');

function tasmota(string $path): ?string {
    $ch = curl_init(TASMOTA . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_HTTPHEADER     => ['Referer: ' . TASMOTA . '/'],
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    return $r === false ? null : $r;
}

function cmnd(string $c): ?array {
    $r = tasmota('/cm?cmnd=' . rawurlencode($c));
    return $r === null ? null : json_decode($r, true);
}

function load_codes(): array {
    $j = @file_get_contents(STORE);
    $d = $j ? json_decode($j, true) : null;
    return is_array($d) ? $d : [];
}

function save_codes(array $codes): bool {
    $tmp = STORE . '.tmp';
    if (file_put_contents($tmp, json_encode($codes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) return false;
    return rename($tmp, STORE);
}

function out($x, int $status = 200): never {
    http_response_code($status);
    echo json_encode($x, JSON_UNESCAPED_SLASHES);
    exit;
}

function clean_name(?string $n): string {
    $n = trim((string)$n);
    if ($n === '' || mb_strlen($n) > 40 || !preg_match('/^[\p{L}\p{N} ._+\-()]+$/u', $n)) out(['error' => 'bad name'], 400);
    return $n;
}

// IrReceived JSON -> the payload IRsend needs (known protocol, or raw timings when unknown)
function to_send(array $ir): ?string {
    $p = $ir['Protocol'] ?? '';
    if ($p !== '' && $p !== 'UNKNOWN' && isset($ir['Bits'], $ir['Data'])) {
        $s = ['Protocol' => $p, 'Bits' => $ir['Bits'], 'Data' => $ir['Data']];
        return json_encode($s, JSON_UNESCAPED_SLASHES);
    }
    if (!empty($ir['RawData'])) return '0,' . $ir['RawData'];
    return null;
}

$a = $_GET['a'] ?? $_POST['a'] ?? '';

switch ($a) {
case 'status':
    $s = cmnd('Status 0');
    if (!$s) out(['online' => false]);
    out([
        'online'   => true,
        'uptime'   => $s['StatusPRM']['Uptime'] ?? null,
        'signal'   => $s['StatusSTS']['Wifi']['Signal'] ?? null,
        'version'  => $s['StatusFWR']['Version'] ?? null,
        'ip'       => $s['StatusNET']['IPAddress'] ?? null,
    ]);

case 'codes':
    out(load_codes());

case 'listen':
    // Returns IR codes logged by Tasmota after log index `i` (0 = only report the current index)
    $i = max(0, (int)($_GET['i'] ?? 0));
    $r = tasmota('/cs?c2=' . $i);
    if ($r === null) out(['error' => 'blaster offline'], 502);
    $idx = preg_match('/^(\d+)\}/', $r, $m) ? (int)$m[1] : $i;
    $found = [];
    if ($i > 0 && preg_match_all('/\{"IrReceived":(\{.*?\})\}/', $r, $mm)) {
        foreach ($mm[1] as $j) {
            $ir = json_decode($j, true);
            if (is_array($ir) && ($send = to_send($ir)) !== null) {
                $found[] = ['protocol' => $ir['Protocol'] ?? 'UNKNOWN', 'bits' => $ir['Bits'] ?? null,
                            'data' => $ir['Data'] ?? null, 'send' => $send];
            }
        }
    }
    out(['i' => $idx, 'codes' => $found]);

case 'save':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error' => 'POST only'], 405);
    $name = clean_name($_POST['name'] ?? '');
    $send = (string)($_POST['send'] ?? '');
    // accept only what to_send() produces: protocol JSON or "0,<raw timings>"
    $ok = (str_starts_with($send, '{') && is_array(json_decode($send, true)))
       || preg_match('/^0,[0-9+\-A-Za-z,.]+$/', $send);
    if (!$ok || strlen($send) > 2000) out(['error' => 'bad code'], 400);
    $codes = load_codes();
    $codes[$name] = ['send' => $send, 'protocol' => (string)($_POST['protocol'] ?? ''), 'learned' => date('c')];
    if (!save_codes($codes)) out(['error' => 'could not save'], 500);
    out(['ok' => true]);

case 'delete':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error' => 'POST only'], 405);
    $name = clean_name($_POST['name'] ?? '');
    $codes = load_codes();
    unset($codes[$name]);
    if (!save_codes($codes)) out(['error' => 'could not save'], 500);
    out(['ok' => true]);

case 'send':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error' => 'POST only'], 405);
    $name = clean_name($_POST['name'] ?? '');
    $codes = load_codes();
    if (!isset($codes[$name])) out(['error' => 'not learned yet'], 404);
    $r = cmnd('IRsend ' . $codes[$name]['send']);
    if ($r === null) out(['error' => 'blaster offline'], 502);
    out(['ok' => true, 'result' => $r]);

default:
    out(['error' => 'unknown action'], 400);
}
