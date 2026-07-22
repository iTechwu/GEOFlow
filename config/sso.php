<?php

return [
    'api_url' => rtrim((string) env('SSO_API_URL', 'https://sso.ixicai.cn/api'), '/'),
    'internal_api_url' => rtrim((string) env('SSO_INTERNAL_API_URL', 'https://sso.ixicai.cn/api'), '/'),
    'issuer' => rtrim((string) env('SSO_ISSUER', 'https://sso.ixicai.cn/api'), '/'),
    'client_id' => (string) env('SSO_CLIENT_ID', ''),
    'client_secret' => (string) env('SSO_CLIENT_SECRET', ''),
    'service_name' => (string) env('SSO_SERVICE_NAME', 'geo.local.dofe.ai'),
    'internal_api_secret' => (string) env('INTERNAL_API_SECRET', ''),
    'redirect_uri' => (string) env('SSO_REDIRECT_URI', 'https://geo.local.dofe.ai/auth/callback'),
    'discovery_url' => (string) env('SSO_DISCOVERY_URL', 'https://sso.ixicai.cn/api/.well-known/openid-configuration'),
    'jwks_path' => (string) env('JWKS_PATH', '.well-known/jwks.json'),
    'jwks_uri' => (string) env('JWKS_URI', 'https://sso.ixicai.cn/api/.well-known/jwks.json'),
    'internal_jwks_uri' => (string) env('INTERNAL_JWKS_URI', 'https://sso.ixicai.cn/api/.well-known/jwks.json'),
    'session_lifetime' => (int) env('SSO_SESSION_LIFETIME', 3600),
];
