<?php

declare(strict_types=1);

/*
 * The demo's webhook receiver: a stand-in for a tenant's system, on the
 * compose network at webhook-receiver:8080 and on the host at
 * http://localhost:8089.
 *
 * The path picks how it behaves, so seeded endpoints can show every outcome
 * the delivery pipeline handles:
 *
 *   /ok      answers 204
 *   /flaky   answers 503 to an event's first two attempts, then 204
 *   /down    always answers 503 — ten attempts later the delivery is dead
 *   /slow    answers after 12 seconds, past the sender's 10-second timeout
 *   /gone    answers 410 — a refusal the sender believes at once
 *
 * GET / lists what arrived, newest first, and whether each signature checks
 * out against the secrets it has been given. POST /_secrets with a form field
 * `secret` gives it one: the secret is shown once, when an endpoint is
 * registered, and this is where to paste it.
 *
 * Demo tooling, not part of the application: plain PHP on the built-in
 * server, files in the temp directory, nothing to install.
 */

require __DIR__ . '/verify.php';

$store = sys_get_temp_dir() . '/metered-webhook-receiver';
@mkdir($store, 0o700, true);

/**
 * A value from a request or a stored row, as a string; anything else is empty.
 */
function text(mixed $value): string
{
    return is_scalar($value) ? (string) $value : '';
}

$path = text(parse_url(text($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
$path = $path === '' ? '/' : $path;
$method = text($_SERVER['REQUEST_METHOD'] ?? 'GET');

/** @return list<string> */
function secrets(string $store): array
{
    $raw = @file_get_contents($store . '/secrets.json');
    $decoded = is_string($raw) ? json_decode($raw, true) : [];

    return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
}

/** @return list<array<array-key, mixed>> */
function received(string $store): array
{
    $lines = @file($store . '/received.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $rows = [];

    foreach (is_array($lines) ? $lines : [] as $line) {
        $row = json_decode($line, true);

        if (is_array($row)) {
            $rows[] = $row;
        }
    }

    return array_reverse($rows);
}

if ($method === 'POST' && $path === '/_secrets') {
    $json = json_decode((string) file_get_contents('php://input'), true);
    $secret = trim(text($_POST['secret'] ?? (is_array($json) ? $json['secret'] ?? '' : '')));

    if (preg_match('/^whsec_[A-Za-z0-9_-]{32,}$/', $secret) === 1) {
        file_put_contents($store . '/secrets.json', json_encode(array_values(array_unique([...secrets($store), $secret]))), LOCK_EX);
    }

    header('Location: /', true, 303);

    return;
}

if ($method === 'GET' && $path === '/_received') {
    header('Content-Type: application/json');
    echo json_encode(received($store), JSON_UNESCAPED_SLASHES);

    return;
}

if ($method === 'POST') {
    $mode = trim($path, '/');
    $body = (string) file_get_contents('php://input');
    $signature = text($_SERVER['HTTP_X_METERED_SIGNATURE'] ?? '');
    $event = text($_SERVER['HTTP_X_METERED_EVENT_ID'] ?? '');

    // Counted per path and event: one event reaches every endpoint that
    // listens to it, and each is a receiver of its own.
    $attemptFile = $store . '/attempts-' . preg_replace('/[^a-z0-9-]/i', '', $mode . '-' . $event);
    $attempt = (int) @file_get_contents($attemptFile) + 1;
    file_put_contents($attemptFile, (string) $attempt, LOCK_EX);

    $status = match ($mode) {
        'ok', 'slow' => 204,
        'flaky' => $attempt <= 2 ? 503 : 204,
        'down' => 503,
        'gone' => 410,
        default => 404,
    };

    if ($mode === 'slow') {
        sleep(12);
    }

    $verified = null;
    foreach (secrets($store) as $secret) {
        if (verify($body, $signature, $secret)) {
            $verified = true;
            break;
        }
        $verified = false;
    }

    file_put_contents($store . '/received.jsonl', json_encode([
        'at' => gmdate('Y-m-d H:i:s'),
        'mode' => $mode,
        'event_id' => $event,
        'event_type' => text($_SERVER['HTTP_X_METERED_EVENT_TYPE'] ?? ''),
        'attempt' => $attempt,
        'answered' => $status,
        'signature' => $verified === null ? 'no secret given' : ($verified ? 'valid' : 'INVALID'),
        // The sender's trace, as a tenant that traces its own backend would
        // continue it: paste the id into Grafana → Explore → Tempo.
        'trace_id' => explode('-', text($_SERVER['HTTP_TRACEPARENT'] ?? ''))[1] ?? '',
        'body' => $body,
    ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

    http_response_code($status);

    return;
}

$rows = '';
foreach (array_slice(received($store), 0, 200) as $r) {
    $rows .= sprintf(
        '<tr><td>%s</td><td>/%s</td><td>%s</td><td><code>%s</code></td><td>%d</td><td>%d</td><td>%s</td><td><code>%s</code></td></tr>',
        htmlspecialchars(text($r['at'] ?? '')),
        htmlspecialchars(text($r['mode'] ?? '')),
        htmlspecialchars(text($r['event_type'] ?? '')),
        htmlspecialchars(text($r['event_id'] ?? '')),
        (int) text($r['attempt'] ?? 0),
        (int) text($r['answered'] ?? 0),
        htmlspecialchars(text($r['signature'] ?? '')),
        htmlspecialchars(text($r['trace_id'] ?? '')),
    );
}

$count = count(secrets($store));
echo <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Webhook receiver</title>
<style>body{font:14px system-ui,sans-serif;margin:2rem}table{border-collapse:collapse;width:100%}td,th{border-bottom:1px solid #ddd;padding:.35rem .5rem;text-align:left}code{font-size:12px}</style></head>
<body><h1>Webhook receiver</h1>
<p>A stand-in for a tenant's system. Paths: <code>/ok</code>, <code>/flaky</code>, <code>/down</code>, <code>/slow</code>, <code>/gone</code>. {$count} secret(s) known.</p>
<form method="post" action="/_secrets"><input name="secret" size="60" placeholder="whsec_… — paste an endpoint's secret to verify its signatures"> <button>Add secret</button></form>
<table><thead><tr><th>Received (UTC)</th><th>Path</th><th>Event</th><th>Event id</th><th>Attempt</th><th>Answered</th><th>Signature</th><th>Trace</th></tr></thead><tbody>{$rows}</tbody></table>
</body></html>
HTML;
