<?php

declare(strict_types=1);

namespace Politiks\App\Ai;

use Closure;
use JsonException;
use Throwable;

final class OpenAiResponsesClient implements AiResponsesClient
{
    /** @var Closure(string,list<string>,string,int):array{status:int,body:string} */
    private readonly Closure $transport;

    /**
     * @param null|Closure(string,list<string>,string,int):array{status:int,body:string} $transport
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $responsesUrl,
        private readonly string $model,
        private readonly int $timeoutSeconds,
        private readonly int $maxOutputTokens,
        ?Closure $transport = null,
    ) {
        $this->transport = $transport ?? $this->curlTransport(...);
    }

    public function structuredResponse(
        string $developerPrompt,
        array $userData,
        string $schemaName,
        array $schema,
        string $safetyIdentifier,
    ): array {
        if ($developerPrompt === '' || strlen($developerPrompt) > 50_000) {
            throw new AiFilterException('AI_PROMPT_INVALID', 'Die KI-Filteranweisung ist ungültig.', 500);
        }
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/', $schemaName) !== 1) {
            throw new AiFilterException('AI_SCHEMA_INVALID', 'Das KI-Antwortformat ist ungültig.', 500);
        }
        if (preg_match('/^[A-Za-z0-9_-]{8,64}$/', $safetyIdentifier) !== 1) {
            throw new AiFilterException('AI_SAFETY_ID_INVALID', 'Die KI-Sicherheitskennung ist ungültig.', 500);
        }

        try {
            $userJson = json_encode(
                $userData,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            $requestBody = json_encode([
                'model' => $this->model,
                'store' => false,
                'input' => [
                    ['role' => 'developer', 'content' => $developerPrompt],
                    ['role' => 'user', 'content' => $userJson],
                ],
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => $schemaName,
                        'strict' => true,
                        'schema' => $schema,
                    ],
                ],
                'max_output_tokens' => $this->maxOutputTokens,
                'safety_identifier' => $safetyIdentifier,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $error) {
            throw new AiFilterException('AI_REQUEST_INVALID', 'Die KI-Anfrage konnte nicht serialisiert werden.', 500, $error);
        }

        try {
            $response = ($this->transport)(
                $this->responsesUrl,
                ['Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey],
                $requestBody,
                $this->timeoutSeconds,
            );
        } catch (AiFilterException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new AiFilterException('AI_PROVIDER_UNAVAILABLE', 'Der KI-Dienst ist derzeit nicht erreichbar.', 503, $error);
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $code = match ($response['status']) {
                401, 403 => 'AI_PROVIDER_CONFIGURATION',
                408, 504 => 'AI_PROVIDER_TIMEOUT',
                429 => 'AI_PROVIDER_RATE_LIMITED',
                default => $response['status'] >= 500 ? 'AI_PROVIDER_UNAVAILABLE' : 'AI_PROVIDER_REJECTED',
            };
            throw new AiFilterException($code, 'Der KI-Dienst konnte die Anfrage nicht verarbeiten.', 503);
        }

        return $this->parseResponse($response['body']);
    }

    /**
     * @return array{data:array<string,mixed>,model:string,usage:array{input_tokens:?int,output_tokens:?int,cached_input_tokens:?int}}
     */
    private function parseResponse(string $body): array
    {
        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new AiFilterException('AI_RESPONSE_INVALID', 'Der KI-Dienst hat eine ungültige Antwort geliefert.', 502, $error);
        }
        if (!is_array($payload) || ($payload['status'] ?? null) !== 'completed' || !is_array($payload['output'] ?? null)) {
            throw new AiFilterException('AI_RESPONSE_INCOMPLETE', 'Die KI-Antwort wurde nicht vollständig abgeschlossen.', 502);
        }

        $outputText = null;
        foreach ($payload['output'] as $output) {
            if (!is_array($output) || ($output['type'] ?? null) !== 'message' || !is_array($output['content'] ?? null)) {
                continue;
            }
            foreach ($output['content'] as $content) {
                if (!is_array($content)) {
                    continue;
                }
                if (($content['type'] ?? null) === 'refusal') {
                    throw new AiFilterException('AI_RESPONSE_REFUSED', 'Der KI-Dienst hat diese Auswahl abgelehnt.', 422);
                }
                if (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
                    $outputText = $content['text'];
                }
            }
        }
        if ($outputText === null) {
            throw new AiFilterException('AI_RESPONSE_INVALID', 'Die KI-Antwort enthält keine Auswahl.', 502);
        }
        try {
            $data = json_decode($outputText, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new AiFilterException('AI_RESPONSE_INVALID', 'Die KI-Auswahl ist kein gültiges JSON.', 502, $error);
        }
        if (!is_array($data)) {
            throw new AiFilterException('AI_RESPONSE_INVALID', 'Die KI-Auswahl entspricht nicht dem erwarteten Format.', 502);
        }

        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];
        $inputDetails = is_array($usage['input_tokens_details'] ?? null) ? $usage['input_tokens_details'] : [];
        return [
            'data' => $data,
            'model' => is_string($payload['model'] ?? null) ? $payload['model'] : $this->model,
            'usage' => [
                'input_tokens' => isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : null,
                'output_tokens' => isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : null,
                'cached_input_tokens' => isset($inputDetails['cached_tokens'])
                    ? (int) $inputDetails['cached_tokens']
                    : null,
            ],
        ];
    }

    /** @param list<string> $headers @return array{status:int,body:string} */
    private function curlTransport(string $url, array $headers, string $body, int $timeoutSeconds): array
    {
        if (!function_exists('curl_init')) {
            throw new AiFilterException('AI_PROVIDER_UNAVAILABLE', 'Die cURL-Erweiterung ist nicht verfügbar.', 503);
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new AiFilterException('AI_PROVIDER_UNAVAILABLE', 'Der KI-Dienst konnte nicht initialisiert werden.', 503);
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
        ]);
        $responseBody = curl_exec($handle);
        if ($responseBody === false) {
            $errorNumber = curl_errno($handle);
            curl_close($handle);
            $code = $errorNumber === CURLE_OPERATION_TIMEDOUT ? 'AI_PROVIDER_TIMEOUT' : 'AI_PROVIDER_UNAVAILABLE';
            throw new AiFilterException($code, 'Der KI-Dienst ist derzeit nicht erreichbar.', 503);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        return ['status' => $status, 'body' => (string) $responseBody];
    }
}
