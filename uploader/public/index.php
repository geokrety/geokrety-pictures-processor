<?php

require __DIR__.'/vendor/autoload.php';

use Aws\S3\S3Client as AWSS3Client;

define('GK_MINIO_SERVER_URL', getenv('GK_MINIO_SERVER_URL') ?: 'http://minio:9000');

define('MINIO_ACCESS_KEY', getenv('MINIO_ACCESS_KEY') ?: null);
define('MINIO_SECRET_KEY', getenv('MINIO_SECRET_KEY') ?: null);

define('GK_MINIO_WEBHOOK_AUTH_TOKEN_PICTURES_PROCESSOR_UPLOADER', getenv('GK_MINIO_WEBHOOK_AUTH_TOKEN_PICTURES_PROCESSOR_UPLOADER') ?: null);
define('GK_PP_TMP_DIR', getenv('GK_PP_TMP_DIR') ?: '/tmp');
define('GK_BUCKET_GEOKRETY_AVATARS_CACHE_CONTROL', getenv('GK_BUCKET_GEOKRETY_AVATARS_CACHE_CONTROL') ?: 600);

// SENTRY CONFIG
define('SENTRY_DSN', getenv('SENTRY_DSN') ?: null);
define('SENTRY_ENV', getenv('SENTRY_ENV') ?: null);
Sentry\init(['dsn' => SENTRY_DSN, 'environment' => SENTRY_ENV]);

$f3 = \Base::instance();
$f3->route('POST /file-uploaded', 'fileUploaded');
$f3->route('HEAD /file-uploaded', function () {});

/**
 * @throws \ImagickException
 */
function fileUploaded(Base $f3) {
    if ($f3->get('HEADERS.Authorization') !== sprintf('Bearer %s', GK_MINIO_WEBHOOK_AUTH_TOKEN_PICTURES_PROCESSOR_UPLOADER)) {
        http_response_code(400);
        echo 'Missing or wrong authorization header';
        $f3->abort();
        throw new Exception('Missing or wrong authorization header');
    }

    $s3 = new AWSS3Client([
        'version' => 'latest',
        'region' => 'us-east-1',
        'endpoint' => GK_MINIO_SERVER_URL,
        'use_path_style_endpoint' => true,
        'credentials' => [
            'key' => MINIO_ACCESS_KEY,
            'secret' => MINIO_SECRET_KEY,
        ],
    ]);

    $body = json_decode($f3->get('BODY'), true);
    if ($body['EventName'] !== 's3:ObjectCreated:Put') {
        throw new Exception('Invalid EventName');
    }

    list($bucket, $key) = explode('/', $body['Key'], 2);
    $imgPath = tempnam(GK_PP_TMP_DIR, $key);

    try {
        $s3->getObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'SaveAs' => $imgPath,
        ]);
    } catch (Exception $exception) {
        http_response_code(404);
        echo 'File not found!';
        $f3->abort();
        throw $exception;
    }

    // Read the downloaded image
    $image = new Imagick();
    try {
        $image->readImage($imgPath);
    } catch (ImagickException $exception) {
        http_response_code(400);
        unlink($imgPath);
        //        Keep file for post-mortem analysis
        //        $s3->deleteObject([
        //            'Bucket' => $bucket,
        //            'Key' => $key,
        //        ]);
        echo 'Invalid file type, this incident will be reported';
        $f3->abort();
        throw $exception;
    }
    unlink($imgPath);
    $image->setImageCompressionQuality(50);
    $image->setImageFormat('png');

    list($bucketDest, $keyDest) = explode('/', $key, 2);
    $s3->putObject([
        'Body' => $image->getImageBlob(),
        'Bucket' => $bucketDest,
        'Key' => $keyDest,
        'ContentType' => 'image/png',
        'CacheControl' => sprintf('public, max-age=%d', GK_BUCKET_GEOKRETY_AVATARS_CACHE_CONTROL),
    ]);

    $s3->deleteObject([
        'Bucket' => $bucket,
        'Key' => $key,
    ]);
}

try {
    $f3->run();
} catch (Exception $exception) {
    Sentry\captureException($exception);
}
