<?php

require __DIR__.'/../../vendor/autoload.php';

use Aws\S3\S3Client as AWSS3Client;
use Phalcon\Mvc\Micro;

// TODO change this when integrated  into normal stack
define('GK_MINIO_SERVER_URL', getenv('GK_MINIO_SERVER_URL') ?: 'https://minio.geokrety.house.kumy.net');
define('GK_MINIO_ACCESS_KEY', getenv('GK_MINIO_ACCESS_KEY') ?: '49nFBuDxc7UdLTywAD2mZJ6h1QnR5K3zo3Vv1cpfu4i8zwKdKH0EA4CqdGs0bzz');
define('GK_MINIO_SECRET_KEY', getenv('GK_MINIO_SECRET_KEY') ?: '4VIlzOOyp6DAYf5uxz0ybEKevfzmVhSHeciXrjpLmfd9w79fWgNYCDxbcPLHYiw');

define('GK_PP_TMP_DIR', getenv('GK_PP_TMP_DIR') ?: '/tmp/gk-pictures-processor-tmp');
define('GK_PP_MAX_DIMENSIONS', getenv('GK_PP_MAX_DIMENSIONS') ?: 1024);
define('GK_PP_THUMBNAILS_DIMENSIONS', getenv('GK_PP_THUMBNAILS_DIMENSIONS') ?: 200);
define('GK_BUCKET_THUMBNAIL_SUFFIX', getenv('GK_BUCKET_THUMBNAIL_SUFFIX') ?: '-thumbnails');
define('GK_BUCKET_GEOKRETY_AVATARS_CACHE_CONTROL', getenv('GK_BUCKET_GEOKRETY_AVATARS_CACHE_CONTROL') ?: 600);

$app = new Micro();

$app->get(
    '/',
    function () {
        echo 'Hello World!';
    }
);

$app->post(
    '/webhook',
    function () use ($app) {
        $s3 = new AWSS3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => GK_MINIO_SERVER_URL,
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => GK_MINIO_ACCESS_KEY,
                'secret' => GK_MINIO_SECRET_KEY,
            ],
        ]);

        $body = $app->request->getJsonRawBody();
        if ($body->EventName !== 's3:ObjectCreated:Put') {
            return;
        }

        $bucket = $body->Records[0]->s3->bucket->name;
        $key = $body->Records[0]->s3->object->key;
        $bucketThumbnail = sprintf('%s%s', $bucket, GK_BUCKET_THUMBNAIL_SUFFIX);
        $imgPath = tempnam(GK_PP_TMP_DIR, $key);

        $s3->getObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'SaveAs' => $imgPath,
        ]);

        // Read the downloaded image
        $image = new Imagick();
        $image->readImage($imgPath);
        $origImage = clone $image;
        autoRotateImage($image);
        pingPongConvert($image);

        // Resize the image to maximum allowed value
        resizeToMax($image, GK_PP_MAX_DIMENSIONS);
        // Skip upload if file is untouched, else we'll have a webhook loop
        if ($origImage->compareImages($image, Imagick::METRIC_MEANSQUAREERROR)[1] > 0) {
            $s3->putObject([
                'Body' => $image->getImageBlob(),
                'Bucket' => $bucket,
                'Key' => $key,
                'ContentType' => 'image/png',
                'CacheControl' => sprintf('public, max-age=%d', GK_BUCKET_GEOKRETY_AVATARS_CACHE_CONTROL),
            ]);
        }

        // Create thumbnail
        resizeToMax($image, GK_PP_THUMBNAILS_DIMENSIONS);
        $s3->putObject([
            'Body' => $image->getImageBlob(),
            'Bucket' => $bucketThumbnail,
            'Key' => $key,
            'ContentType' => 'image/png',
            'CacheControl' => sprintf('public, max-age=%d', GK_BUCKET_GEOKRETY_AVATARS_CACHE_CONTROL),
        ]);
    }
);

$app->notFound(function () use ($app) {
    $app->response->setStatusCode(404, 'Not Found')->sendHeaders();
    echo 'This is crazy, but this page was not found!';
});

$app->handle(
    $_SERVER['REQUEST_URI']
);

function pingPongConvert(Imagick $image) {
    $imgPath = tempnam(GK_PP_TMP_DIR, 'gk');
    $image->writeImage(sprintf('ppm:%s', $imgPath));
    $image->clear();
    $image->readImage($imgPath);
    unlink($imgPath);
    $image->setImageCompressionQuality(50);
    $image->setImageFormat('png');
}

function resizeToMax(Imagick $image, $dimensions) {
    if ($image->getImageWidth() > $dimensions || $image->getImageHeight() > $dimensions) {
        if ($image->getImageWidth() > $image->getImageHeight()) {
            $image->resizeImage($dimensions, 0, Imagick::FILTER_CATROM, 1.0);
            return;
        }
        $image->resizeImage(0, $dimensions, Imagick::FILTER_CATROM, 1.0);
    }
}

// https://www.php.net/manual/en/imagick.getimageorientation.php#111448
function autoRotateImage(Imagick $image) {
    $orientation = $image->getImageOrientation();

    switch ($orientation) {
        case imagick::ORIENTATION_BOTTOMRIGHT:
            $image->rotateimage('#000', 180); // rotate 180 degrees
            break;

        case imagick::ORIENTATION_RIGHTTOP:
            $image->rotateimage('#000', 90); // rotate 90 degrees CW
            break;

        case imagick::ORIENTATION_LEFTBOTTOM:
            $image->rotateimage('#000', -90); // rotate 90 degrees CCW
            break;
    }

    // Now that it's auto-rotated, make sure the EXIF data is correct in case the EXIF gets saved with the image!
    $image->setImageOrientation(imagick::ORIENTATION_TOPLEFT);
}
