<?php

require __DIR__.'/../../vendor/autoload.php';

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client as AWSS3Client;

define('GK_MINIO_SERVER_URL', getenv('GK_MINIO_SERVER_URL') ?: 'http://minio:9000');

define('GK_MINIO_PICTURES_PROCESSOR_MINIO_ACCESS_KEY', getenv('GK_MINIO_PICTURES_PROCESSOR_MINIO_ACCESS_KEY') ?: null);
define('GK_MINIO_PICTURES_PROCESSOR_MINIO_SECRET_KEY', getenv('GK_MINIO_PICTURES_PROCESSOR_MINIO_SECRET_KEY') ?: null);
define('GK_MINIO_WEBHOOK_AUTH_TOKEN_PICTURES_PROCESSOR_DOWNLOADER', getenv('GK_MINIO_WEBHOOK_AUTH_TOKEN_PICTURES_PROCESSOR_DOWNLOADER') ?: null);
define('GK_AUTH_TOKEN_DROP_S3_FILE_UPLOAD_REQUEST', getenv('GK_AUTH_TOKEN_DROP_S3_FILE_UPLOAD_REQUEST') ?: '');

define('GK_BUCKET_NAME_GEOKRETY_AVATARS', getenv('GK_BUCKET_NAME_GEOKRETY_AVATARS') ?: 'gk-avatars');
define('GK_BUCKET_NAME_USERS_AVATARS', getenv('GK_BUCKET_NAME_USERS_AVATARS') ?: 'users-avatars');
define('GK_BUCKET_NAME_MOVES_PICTURES', getenv('GK_BUCKET_NAME_MOVES_PICTURES') ?: 'moves-pictures');
define('GK_BUCKET_NAME_PICTURES_PROCESSOR_UPLOADER', getenv('GK_BUCKET_NAME_PICTURES_PROCESSOR_UPLOADER') ?: 'pictures-processor-uploader');

define('GK_PP_TMP_DIR', getenv('GK_PP_TMP_DIR') ?: '/tmp');
define('GK_PP_MAX_DIMENSIONS', getenv('GK_PP_MAX_DIMENSIONS') ?: 1024);
define('GK_PP_THUMBNAILS_DIMENSIONS', getenv('GK_PP_THUMBNAILS_DIMENSIONS') ?: 200);
define('GK_BUCKET_THUMBNAIL_SUFFIX', getenv('GK_BUCKET_THUMBNAIL_SUFFIX') ?: '-thumbnails');

// SENTRY CONFIG
define('SENTRY_DSN', getenv('SENTRY_DSN') ?: null);
Sentry\init(['dsn' => SENTRY_DSN]);

$hasThumbnails = [
    GK_BUCKET_NAME_GEOKRETY_AVATARS,
    GK_BUCKET_NAME_USERS_AVATARS,
    GK_BUCKET_NAME_MOVES_PICTURES,
];

$f3 = \Base::instance();
$f3->route('POST /file-uploaded', 'fileUploaded');

// TODO: Add some checks on uploaded file
// examples: https://security.stackexchange.com/questions/8587/how-can-i-be-protected-from-pictures-vulnerabilities/8625#8625
//           https://security.stackexchange.com/questions/235/what-steps-should-be-taken-to-validate-user-uploaded-images-within-an-applicatio/3016#3016

function fileUploaded(\Base $f3) {
    global $hasThumbnails;

    if ($f3->get('HEADERS.Authorization') !== sprintf('Bearer %s', GK_MINIO_WEBHOOK_AUTH_TOKEN_PICTURES_PROCESSOR_DOWNLOADER)) {
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
            'key' => GK_MINIO_PICTURES_PROCESSOR_MINIO_ACCESS_KEY,
            'secret' => GK_MINIO_PICTURES_PROCESSOR_MINIO_SECRET_KEY,
        ],
    ]);
    //file_put_contents('/tmp/logs', sprintf('curl -v http://pictures-processor-downloader/file-uploaded -d \'%s\' -H \'Content-Type: application/json\'', $app->request->getRawBody()));

    $body = json_decode($f3->get('BODY'), true);
    if (!in_array($body['EventName'], ['s3:ObjectCreated:Put', 's3:ObjectCreated:Post'])) {
        return;
    }

    list($bucket, $key) = explode('/', $body['Key'], 2);
    list($bucketDest, $keyDest) = explode('/', $key, 2);
    $imgPath = tempnam(GK_PP_TMP_DIR, $key);

    try {
        $s3->getObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'SaveAs' => $imgPath,
        ]);
    } catch (S3Exception $exception) {
        Sentry\captureException($exception);
        http_response_code(404);
        echo 'File not found!';

        return;
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
        // TODO call API to remove entry in database
        $ch = curl_init(sprintf('http://website/geokrety/avatar/%s/drop-s3-file-signature', $keyDest));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [sprintf('Authorization: Bearer %s', GK_AUTH_TOKEN_DROP_S3_FILE_UPLOAD_REQUEST)]);
        curl_exec($ch);
        Sentry\captureException($exception);

        return;
    }
    unlink($imgPath);
    $origImage = clone $image;
    autoRotateImage($image);
    $image->setImageFormat('bmp');

    // Resize the image to maximum allowed value
    resizeToMax($image, GK_PP_MAX_DIMENSIONS);
    // Skip upload if file is untouched, else we'll have a webhook loop
    if ($origImage->compareImages($image, Imagick::METRIC_MEANSQUAREERROR)[1] === 0) {
        return;
    }

    $s3->putObject([
        'Body' => $image->getImageBlob(),
        'Bucket' => GK_BUCKET_NAME_PICTURES_PROCESSOR_UPLOADER,
        'Key' => $key,
        'ContentType' => 'image/bmp',
    ]);

    // Create thumbnail
    if (in_array($bucketDest, $hasThumbnails)) {
        $bucketThumbnail = sprintf('%s%s', $bucketDest, GK_BUCKET_THUMBNAIL_SUFFIX);
        resizeToMax($image, GK_PP_THUMBNAILS_DIMENSIONS);
        $s3->putObject([
            'Body' => $image->getImageBlob(),
            'Bucket' => GK_BUCKET_NAME_PICTURES_PROCESSOR_UPLOADER,
            'Key' => sprintf('%s/%s', $bucketThumbnail, $keyDest),
            'ContentType' => 'image/bmp',
        ]);
    }

    $s3->deleteObject([
        'Bucket' => $bucket,
        'Key' => $key,
    ]);
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

$f3->run();
