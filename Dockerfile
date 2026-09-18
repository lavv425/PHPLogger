# PHP 7.4.3 is the oldest runtime this package supports, so the suite runs
# against it rather than against whatever the developer machine happens to have.
#
# Nothing here uses apt: Debian buster is archived and its repositories no
# longer answer, which rules out installing ext-zip or unzip in the runtime
# image. Composer therefore resolves dependencies in its own image, where the
# tooling already exists, and only the resulting vendor/ is carried over.
#
# That is safe because composer.json pins config.platform.php to 7.4.3, so the
# resolver targets this runtime no matter which PHP runs Composer.

FROM composer:2.2 AS vendor

WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --no-progress --no-scripts


FROM php:7.4.3-cli

# pcov rather than Xdebug: an order of magnitude faster for line coverage, and
# this image never needs a step debugger. It has no external dependencies, so
# pecl can build it with the toolchain already in the base image. Disabled by
# default here; the "coverage" compose service turns it on.
RUN pecl install pcov-1.0.11 \
    && docker-php-ext-enable pcov \
    && echo 'pcov.enabled=0' > /usr/local/etc/php/conf.d/pcov.ini

WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY . .

CMD ["vendor/bin/phpunit"]
