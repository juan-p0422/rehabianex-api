<?php

// Proveedor sintético exclusivo para QA local. No registra request bodies.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
    || parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) !== '/chat') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'not_found']);

    return;
}

header('Content-Type: application/json');
echo json_encode([
    'choices' => [[
        'message' => [
            'content' => 'Respuesta sintética segura del proveedor local de QA.',
        ],
    ]],
    'usage' => [
        'prompt_tokens' => 10,
        'completion_tokens' => 8,
        'total_tokens' => 18,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
