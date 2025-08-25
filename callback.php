<?php
$data = json_decode(file_get_contents("php://input"), true);
if (isset($data['part2'])) {
    file_put_contents(__DIR__ . "/part2.json", json_encode($data));
    echo json_encode(["status" => "ok"]);
} else {
    http_response_code(400);
    echo json_encode(["error" => "неверный payload"]);
}