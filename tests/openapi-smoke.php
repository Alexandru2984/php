#!/usr/bin/env php
<?php

declare(strict_types=1);

$document = json_decode(
    (string) file_get_contents(dirname(__DIR__) . '/openapi.json'),
    true,
    64,
    JSON_THROW_ON_ERROR
);

$checks = [
    ($document['openapi'] ?? null) === '3.1.0',
    ($document['info']['version'] ?? null) === '1.1.0',
    ($document['servers'][0]['url'] ?? null) === 'https://php.micutu.com',
    array_key_exists('get', $document['paths']['/api/v1/messages'] ?? []),
    array_key_exists('head', $document['paths']['/api/v1/messages'] ?? []),
    ($document['components']['parameters']['Limit']['schema']['maximum'] ?? null) === 50,
    ($document['components']['schemas']['MessagePage']['properties']['api_version']['const'] ?? null) === '1.0',
    in_array('featured', $document['components']['schemas']['Message']['required'] ?? [], true),
    ($document['components']['schemas']['Message']['properties']['featured']['type'] ?? null) === 'boolean',
    ($document['components']['responses']['MethodNotAllowed']['headers']['Allow']['schema']['const'] ?? null) === 'GET, HEAD',
    ($document['security'] ?? null) === [],
];

foreach ($checks as $check) {
    if ($check !== true) {
        fwrite(STDERR, "OpenAPI contract smoke test failed.\n");
        exit(1);
    }
}

fwrite(STDOUT, "OpenAPI contract smoke test passed.\n");
