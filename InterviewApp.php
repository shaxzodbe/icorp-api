<?php
require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Process\Process;

class InterviewApp
{
    private string $endpoint = "https://test.icorp.uz/private/interview.php";
    private string $callbackFile = __DIR__ . "/part2.json";

    public function run(): void
    {
        $this->startServer();
        $publicUrl = $this->startNgrok();
        echo "🌍 Публичный URL: $publicUrl\n";

        $part1 = $this->getPart1($publicUrl);
        $part2 = $this->waitForPart2();
        $code  = $part1 . $part2;

        $final = $this->sendPost($this->endpoint, ["code" => $code], false);

        echo "🎉 Итоговый ответ:\n" . ($final ?: 'Пустой ответ') . "\n";
    }

    private function startServer(): void
    {
        $server = new Process(['php', '-S', '0.0.0.0:8080', '-t', __DIR__]);
        $server->start();
        file_put_contents(__DIR__ . "/callback.php", $this->callbackHandlerCode());
        sleep(1);
    }

    private function startNgrok(): string
    {
        $ngrok = new Process(['ngrok', 'http', '8080']);
        $ngrok->start();
        sleep(3);

        $ch = curl_init("http://127.0.0.1:4040/api/tunnels");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $data = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (!isset($data['tunnels'][0]['public_url'])) {
            exit("❌ Ошибка: не удалось получить публичный URL от ngrok\n");
        }

        return $data['tunnels'][0]['public_url'];
    }

    private function getPart1(string $publicUrl): string
    {
        $payload = ["msg" => "Привет из PHP", "uri" => $publicUrl . "/callback.php"];
        $response = $this->sendPost($this->endpoint, $payload);

        if (!$response || !isset($response['part1'])) {
            exit("❌ Ошибка: не удалось получить первую часть кода (part1)\n");
        }

        echo "✅ Получена часть 1 (part1): {$response['part1']}\n";
        return trim($response['part1']);
    }

    private function waitForPart2(): string
    {
        echo "⏳ Ожидание callback с частью 2 кода...\n";
        $waited = 0;
        while ($waited < 30) {
            if (file_exists($this->callbackFile)) {
                $data = json_decode(file_get_contents($this->callbackFile), true);
                if (isset($data['part2'])) {
                    echo "✅ Получена часть 2 (part2): {$data['part2']}\n";
                    return trim($data['part2']);
                }
            }
            sleep(2);
            $waited += 2;
        }
        exit("❌ Ошибка: таймаут ожидания part2\n");
    }

    private function sendPost(string $url, array $data, bool $expectJson = true): mixed
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
            CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            echo "❌ cURL ошибка: " . curl_error($ch) . "\n";
            curl_close($ch);
            return null;
        }

        curl_close($ch);

        echo "⬅️ HTTP код ответа: $httpCode\n";
        echo "⬅️ Сырой ответ:\n$result\n";

        if ($expectJson) {
            $json = json_decode($result, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo "⚠️ Ошибка декодирования JSON: " . json_last_error_msg() . "\n";
                return null;
            }
            return $json;
        }

        return $result;
    }

    private function callbackHandlerCode(): string
    {
        return <<<'PHP'
<?php
$data = json_decode(file_get_contents("php://input"), true);
if (isset($data['part2'])) {
    file_put_contents(__DIR__ . "/part2.json", json_encode($data));
    echo json_encode(["status" => "ok"]);
} else {
    http_response_code(400);
    echo json_encode(["error" => "неверный payload"]);
}
PHP;
    }
}

$app = new InterviewApp();
$app->run();
