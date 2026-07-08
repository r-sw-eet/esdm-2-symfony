FROM php:8.3-cli

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip git curl ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Bake the upstream esdm CLI in so `esdmgen generate` can lint the model before
# generating. ESDM_VERSION pins the upstream esdm release.
ARG ESDM_VERSION=0.12.0
ARG TARGETARCH=amd64
RUN curl -fsSL "https://esdm.s3.fr-par.scw.cloud/${ESDM_VERSION}/esdm-linux-${TARGETARCH}" -o /usr/local/bin/esdm \
    && chmod +x /usr/local/bin/esdm \
    && esdm version

WORKDIR /generator

COPY composer.json ./
RUN composer install --no-interaction --no-progress --no-dev

COPY bin/ bin/
COPY src/ src/

WORKDIR /work
ENTRYPOINT ["php", "/generator/bin/esdmgen"]
CMD ["targets"]
