# GeoKrety Pictures Processor

Is a small https://geokrety.org/ internal API to process pictures.

Currently support:
* downsizing to max size
* create thumbnails

It takes it's actions from s3 webhooks events, process the image and upload
image back to buckets.
