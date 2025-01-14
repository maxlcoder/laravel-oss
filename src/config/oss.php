<?php

return [
    'endpoint_upload' => env('OSS_ENDPOINT_UPLOAD'),
    'endpoint' => env('OSS_ENDPOINT'),
    'access_key' => env('OSS_ACCESS_KEY'),
    'secret_key' => env('OSS_SECRET_KEY'),
    'bucket' => env('OSS_BUCKET'),
    'path' => env('OSS_PATH',''),
    'region' => env('OSS_REGION','cn-hangzhou'),
];
