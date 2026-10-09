<?php
// Public, read-only JSON snapshot of all four signals. Poll once per second.
require __DIR__ . '/../../src/bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
try {
    echo json_encode(build_state(db()), JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['error' => 'unavailable']);
}
