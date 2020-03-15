# GeoKrety Pictures Processor

[![Codacy Badge](https://api.codacy.com/project/badge/Grade/05906125796c472b9110c175c4bff918)](https://app.codacy.com/gh/geokrety/geokrety-pictures-processor?utm_source=github.com&utm_medium=referral&utm_content=geokrety/geokrety-pictures-processor&utm_campaign=Badge_Grade_Dashboard)

Is a small https://geokrety.org/ internal API to process pictures.

Currently support:
* downsizing to max size
* create thumbnails

It takes it's actions from s3 webhooks events, process the image and upload
image back to buckets.
