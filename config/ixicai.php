<?php

return [
    'api_url' => rtrim((string) env('IXICAI_API_URL', 'https://ixicai.ai'), '/'),
    'chat_base_url' => rtrim((string) env('IXICAI_CHAT_BASE_URL', 'https://ixicai.ai/v1'), '/'),
    'provision_path' => '/'.ltrim((string) env('IXICAI_API_KEY_PROVISION_PATH', '/user-api-keys'), '/'),
    'default_key_name' => (string) env('IXICAI_DEFAULT_KEY_NAME', 'geo.dofe'),
];
