# Upgrading

Review release notes and the complete diff before upgrading. Treat migrations as potentially irreversible unless a release explicitly documents otherwise.

## Before the Upgrade

1. Record the current release or commit and image digests.
2. Read release notes for configuration, runtime, provider-policy, and schema changes.
3. Build and test new immutable images before touching production.
4. Back up PostgreSQL, the complete Laravel `storage/` directory, `APP_KEY`, other secrets, and deployment configuration.
5. Verify that the database backup can be read and that the previous images remain available.
6. Pause the scheduler and drain or stop queue workers cleanly.

Never rotate or replace `APP_KEY` as part of a routine upgrade. It is required to decrypt provider credentials stored in PostgreSQL.

## Deploy

1. Put the application in maintenance mode if the release cannot remain compatible during migration:

   ```bash
   php artisan down
   ```

2. Deploy the new application and web images with the existing persistent `storage/` mount and secrets.
3. Run migrations once:

   ```bash
   php artisan migrate --force
   ```

4. Clear and rebuild runtime caches as appropriate for the deployment:

   ```bash
   php artisan optimize:clear
   php artisan optimize
   ```

5. Restart all queue workers so they load the new code.
6. Resume the scheduler and workers.
7. Leave maintenance mode:

   ```bash
   php artisan up
   ```

8. Verify `/up`, owner login, library/search pages, a Plex dry run, artwork, queue processing, and scheduler execution.

## Rollback

Application rollback is safe only when the previous code supports the migrated schema. If compatibility is not explicitly documented, stop the application, workers, and scheduler; restore PostgreSQL and `storage/` from the same pre-upgrade backup set; restore the previous secrets and configuration; and redeploy the previous images.

Do not run `migrate:rollback` blindly. Data migrations and external side effects may not be reversible. Keep the failed release's logs private and sanitized before sharing them in an issue.
