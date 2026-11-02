#!/bin/sh
set -eu
exec php artisan queue:work "${ARNA_OCR_QUEUE_CONNECTION:-database}" \
  --queue="${ARNA_OCR_QUEUE:-ocr}" \
  --sleep=3 --tries=2 --timeout=300 --max-time=3600
