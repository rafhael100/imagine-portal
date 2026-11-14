FROM imagine-portal-private-auth:local
USER root
COPY apps/portal/composer.lock /tmp/preview-composer.lock
RUN cmp composer.lock /tmp/preview-composer.lock && rm /tmp/preview-composer.lock
COPY apps/portal/app/Domain ./app/Domain
COPY apps/portal/app/Presentation ./app/Presentation
COPY apps/portal/resources/views/portal/demo ./resources/views/portal/demo
COPY apps/portal/resources/views/portal/preview ./resources/views/portal/preview
COPY apps/portal/ops/private-purchase/full-layout.blade.php ./resources/views/components/portal/layout.blade.php
COPY apps/portal/app/Http/Controllers/CustomerPreviewController.php ./app/Http/Controllers/
COPY apps/portal/app/Http/Controllers/FullPortalPreviewController.php ./app/Http/Controllers/
COPY apps/portal/app/Providers/FullPortalPreviewServiceProvider.php ./app/Providers/
COPY apps/portal/app/Homologation ./app/Homologation
COPY apps/portal/app/Http/Controllers/PurchaseRehearsalController.php ./app/Http/Controllers/
COPY apps/portal/app/Providers/PurchaseRehearsalServiceProvider.php ./app/Providers/
COPY apps/portal/resources/views/portal/rehearsal ./resources/views/portal/rehearsal
COPY apps/portal/ops/private-purchase/router.php ./private-purchase-router.php
COPY apps/portal/ops/private-purchase/private-preview-route-gate.php ./private-preview-route-gate.php
RUN COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --no-interaction --no-scripts --no-plugins --classmap-authoritative
USER 82:82
ENTRYPOINT ["/bin/sh", "-ec"]
CMD ["mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; exec php -S 0.0.0.0:8080 private-purchase-router.php"]
