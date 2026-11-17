<?php
// Railway Healthcheck Endpoint
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'status'  => 'healthy',
    'app'     => 'Tesla Energy Foundation Mailer',
    'version' => '2.0.0',
    'time'    => date('c')
]);
