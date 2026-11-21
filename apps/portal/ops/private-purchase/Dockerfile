FROM imagine-portal-private-auth:local
USER root
COPY apps/portal/composer.lock /tmp/preview-composer.lock
RUN cmp composer.lock /tmp/preview-composer.lock && rm /tmp/preview-composer.lock
COPY apps/portal/app/Domain ./app/Domain
COPY apps/portal/app/Presentation ./app/Presentation
COPY apps/portal/resources/views/portal/demo ./resources/views/portal/demo
COPY apps/portal/resources/views/portal/preview ./resources/views/portal/preview
COPY apps/portal/ops/private-purchase/full-layout.blade.php ./resources/views/components/portal/layout.blade.php
COPY apps/portal/app/Http/Controllers/CustomerWorkPreviewController.php ./app/Http/Controllers/
COPY apps/portal/app/Http/Controllers/CustomerPreviewController.php ./app/Http/Controllers/
COPY apps/portal/app/Http/Controllers/PerformanceOrderSnapshotLabController.php ./app/Http/Controllers/
COPY apps/portal/app/Http/Controllers/PerformanceS2SLabController.php ./app/Http/Controllers/
COPY apps/portal/app/Http/Controllers/FullPortalPreviewController.php ./app/Http/Controllers/
COPY apps/portal/app/Providers/FullPortalPreviewServiceProvider.php ./app/Providers/
COPY apps/portal/app/Homologation ./app/Homologation
COPY apps/portal/tools/test_commerce.php ./tools/test_commerce.php
COPY apps/portal/tools/test_billing_schedule.php ./tools/test_billing_schedule.php
COPY apps/portal/tools/test_performance_settlement.php ./tools/test_performance_settlement.php
COPY apps/portal/tools/operator_performance_report.php ./tools/operator_performance_report.php
COPY apps/portal/app/Http/Controllers/PurchaseRehearsalController.php ./app/Http/Controllers/
COPY apps/portal/app/Providers/PurchaseRehearsalServiceProvider.php ./app/Providers/
COPY apps/portal/resources/views/portal/rehearsal ./resources/views/portal/rehearsal
COPY apps/portal/ops/private-purchase/f423-approval-preflight.php ./f423-approval-preflight.php
COPY apps/portal/ops/private-purchase/f423-performance-bridge-preflight.php ./f423-performance-bridge-preflight.php
COPY apps/portal/ops/private-purchase/f423-google-plan-preflight.php ./f423-google-plan-preflight.php
COPY apps/portal/ops/private-purchase/f423-google-plan-approval-preflight.php ./f423-google-plan-approval-preflight.php
COPY apps/portal/ops/private-purchase/f423-google-internal-draft-preflight.php ./f423-google-internal-draft-preflight.php
COPY apps/portal/ops/private-purchase/f423-google-monitoring-preflight.php ./f423-google-monitoring-preflight.php
COPY apps/portal/ops/private-purchase/f423-google-improvement-proposal-preflight.php ./f423-google-improvement-proposal-preflight.php
COPY apps/portal/ops/private-purchase/f423-google-improvement-approval-preflight.php ./f423-google-improvement-approval-preflight.php
COPY apps/portal/ops/private-purchase/f423-google-offline-application-preflight.php ./f423-google-offline-application-preflight.php
COPY apps/portal/ops/private-purchase/private-preview-route-gate.php ./private-preview-route-gate.php
COPY apps/portal/ops/private-purchase/test_route_gate.php ./private-preview-route-gate.test.php
COPY apps/portal/ops/private-purchase/router.php ./private-purchase-router.php
RUN COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --no-interaction --no-scripts --no-plugins --classmap-authoritative
USER 82:82
ENTRYPOINT ["/bin/sh", "-ec"]
CMD ["mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; exec php -S 0.0.0.0:8080 private-purchase-router.php"]
