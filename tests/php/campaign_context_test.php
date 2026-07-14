<?php

declare(strict_types=1);

use Politiks\App\Insight\CampaignContextStore;

require_once __DIR__ . '/../../site/backend/bootstrap.php';

return [
    'YouTube URLs are narrowly parsed and normalized from known forms' => static function (): void {
        assertSameValue('dQw4w9WgXcQ', CampaignContextStore::youtubeVideoId('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=5'), 'Watch URL should parse.');
        assertSameValue('dQw4w9WgXcQ', CampaignContextStore::youtubeVideoId('https://youtu.be/dQw4w9WgXcQ'), 'Short URL should parse.');
        assertSameValue(null, CampaignContextStore::youtubeVideoId('https://example.com/watch?v=dQw4w9WgXcQ'), 'Untrusted host must fail.');
        assertSameValue(null, CampaignContextStore::youtubeVideoId('https://www.youtube.com/embed/dQw4w9WgXcQ'), 'Unsupported YouTube path must fail.');
        assertSameValue(null, CampaignContextStore::youtubeVideoId('javascript:alert(1)'), 'Non-HTTPS input must fail.');
    },
];
