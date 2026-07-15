<?php

declare(strict_types=1);

namespace Politiks\App\Ai;

final class AiResponsesClientFactory
{
    /** @param array{enabled:bool,api_key:?string,responses_url:string,model:string,timeout_seconds:int,max_output_tokens:int} $config */
    public static function create(array $config): AiResponsesClient
    {
        if (!$config['enabled']) {
            throw new AiFilterException(
                'AI_FILTER_DISABLED',
                'Der KI-Filter ist derzeit nicht aktiviert.',
                503,
            );
        }
        if ($config['api_key'] === null || $config['api_key'] === '') {
            throw new AiFilterException(
                'AI_FILTER_UNAVAILABLE',
                'Der KI-Filter ist derzeit nicht verfügbar.',
                503,
            );
        }
        return new OpenAiResponsesClient(
            $config['api_key'],
            $config['responses_url'],
            $config['model'],
            $config['timeout_seconds'],
            $config['max_output_tokens'],
        );
    }
}
