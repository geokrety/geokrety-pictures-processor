<?php

require __DIR__.'/../../vendor/autoload.php';

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client as AWSS3Client;

define('GK_MINIO_SERVER_URL', getenv('GK_MINIO_SERVER_URL') ?: 'http://minio:9000');

define('MINIO_ACCESS_KEY', getenv('MINIO_ACCESS_KEY') ?: null);
define('MINIO_SECRET_KEY', getenv('MINIO_SECRET_KEY') ?: null);

define('GK_MINIO_WEBHOOK_AUTH_TOKEN_PICTURES_PROCESSOR_UPLOADER', getenv('GK_MINIO_WEBHOOK_AUTH_TOKEN_PICTURES_PROCESSOR_UPLOADER') ?: null);
define('GK_PP_TMP_DIR', getenv('GK_PP_TMP_DIR') ?: '/tmp/gk-pictures-processor-tmp');
define('GK_BUCKET_GEOKRETY_AVATARS_CACHE_CONTROL', getenv('GK_BUCKET_GEOKRETY_AVATARS_CACHE_CONTROL') ?: 600);

$f3 = \Base::instance();
$f3->route('POST /file-uploaded', 'fileUploaded');

function fileUploaded(\Base $f3) {
    if ($f3->get('HEADERS.Authorization') !== sprintf('Bearer %s', GK_MINIO_WEBHOOK_AUTH_TOKEN_PICTURES_PROCESSOR_UPLOADER)) {
        http_response_code(400);
        echo 'Missing or wrong authorization header';
        die();
    }

    file_put_contents('/tmp/headers', print_r($f3->get('HEADERS'), true));
    file_put_contents('/tmp/body', $f3->get('BODY'));

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
        return;
    }

    list($bucket, $key) = explode('/', $body['Key'], 2);
    $imgPath = tempnam(GK_PP_TMP_DIR, $key);

    try {
        $s3->getObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'SaveAs' => $imgPath,
        ]);
    } catch (S3Exception $exception) {
//        $app->response->setStatusCode(404, 'Not Found')->sendHeaders();
        echo 'File not found!';

        return;
    }

    // Read the downloaded image
    $image = new Imagick();
    $image->readImage($imgPath);
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

$f3->run();
