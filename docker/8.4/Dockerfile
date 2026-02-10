FROM php:8.4-cli-bookworm

# System dependencies
ARG WWWGROUP=1000
ARG WWWUSER=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libaio1 unzip curl libzip-dev \
    && docker-php-ext-install zip ftp \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- \
    --install-dir=/usr/local/bin --filename=composer

# Create Sail user (same as official Laravel Sail)
RUN groupadd --force -g ${WWWGROUP} sail \
    && useradd -ms /bin/bash --no-user-group -g ${WWWGROUP} -u ${WWWUSER} sail

WORKDIR /var/www/html

# Oracle Instant Client (Linux)
COPY oracle/instantclient /opt/oracle/instantclient

# Recreate symlinks (Windows stubs do not work on Linux)
RUN cd /opt/oracle/instantclient \
    && rm -f libclntsh.so libclntsh.so.10.1 libclntsh.so.11.1 libclntsh.so.12.1 libclntsh.so.18.1 libclntsh.so.19.1 libclntsh.so.20.1 \
    && rm -f libocci.so libocci.so.10.1 libocci.so.11.1 libocci.so.12.1 libocci.so.18.1 libocci.so.19.1 libocci.so.20.1 \
    && rm -f libclntshcore.so libclntshcore.so.12.1 libclntshcore.so.18.1 libclntshcore.so.19.1 libclntshcore.so.20.1 \
    && rm -f libocci_gcc53.so \
    && ln -s libclntsh.so.21.1 libclntsh.so \
    && ln -s libclntsh.so.21.1 libclntsh.so.20.1 \
    && ln -s libclntsh.so.21.1 libclntsh.so.19.1 \
    && ln -s libclntsh.so.21.1 libclntsh.so.18.1 \
    && ln -s libclntsh.so.21.1 libclntsh.so.12.1 \
    && ln -s libclntsh.so.21.1 libclntsh.so.11.1 \
    && ln -s libclntsh.so.21.1 libclntsh.so.10.1 \
    && ln -s libocci.so.21.1 libocci.so \
    && ln -s libocci.so.21.1 libocci.so.20.1 \
    && ln -s libocci.so.21.1 libocci.so.19.1 \
    && ln -s libocci.so.21.1 libocci.so.18.1 \
    && ln -s libocci.so.21.1 libocci.so.12.1 \
    && ln -s libocci.so.21.1 libocci.so.11.1 \
    && ln -s libocci.so.21.1 libocci.so.10.1 \
    && ln -s libclntshcore.so.21.1 libclntshcore.so \
    && ln -s libclntshcore.so.21.1 libclntshcore.so.20.1 \
    && ln -s libclntshcore.so.21.1 libclntshcore.so.19.1 \
    && ln -s libclntshcore.so.21.1 libclntshcore.so.18.1 \
    && ln -s libclntshcore.so.21.1 libclntshcore.so.12.1 \
    && ln -s libocci_gcc53.so.21.1 libocci_gcc53.so

ENV LD_LIBRARY_PATH=/opt/oracle/instantclient
ENV ORACLE_HOME=/opt/oracle/instantclient

# OCI8
RUN echo "instantclient,/opt/oracle/instantclient" | pecl install oci8 \
    && docker-php-ext-enable oci8