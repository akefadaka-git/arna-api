FROM php:8.3-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libicu-dev libonig-dev libxml2-dev libzip-dev unzip git \
        tesseract-ocr tesseract-ocr-amh tesseract-ocr-eng poppler-utils \
    && docker-php-ext-install bcmath intl mbstring pcntl pdo_mysql xml zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/arna
COPY . .
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader \
    && mkdir -p /tmp/arna-ocr storage/app/private/ocr-tmp \
    && chown -R www-data:www-data /var/www/arna /tmp/arna-ocr \
    && chmod 0700 /tmp/arna-ocr

COPY docker/ocr-worker/entrypoint.sh /usr/local/bin/arna-ocr-worker
RUN chmod 0555 /usr/local/bin/arna-ocr-worker

ENV ARNA_OCR_ENABLED=true \
    ARNA_OCR_TESSERACT_BINARY=/usr/bin/tesseract \
    ARNA_OCR_PDFTOPPM_BINARY=/usr/bin/pdftoppm \
    ARNA_OCR_PDFINFO_BINARY=/usr/bin/pdfinfo \
    ARNA_OCR_TEMP_DIRECTORY=/tmp/arna-ocr \
    ARNA_OCR_QUEUE=ocr \
    ARNA_OCR_QUEUE_CONNECTION=database

USER www-data
CMD ["sh", "/usr/local/bin/arna-ocr-worker"]
