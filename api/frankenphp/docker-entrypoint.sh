#!/bin/sh
set -e

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then

	# Reinstall when vendor/ is empty (fresh checkout) or when composer.lock has
	# drifted from what's installed on the host bind mount (e.g. after a pull
	# that adds bundles like symfony/monolog-bundle). This reconciliation only
	# applies to the dev image, whose bind-mounted source can drift; the slim
	# prod image (frankenphp_prod) bakes immutable vendors at build time and
	# ships no composer binary, so guard on its presence to avoid an exit-127
	# `composer: not found` boot loop in prod.
	if command -v composer >/dev/null 2>&1; then
		LOCK_HASH=""
		if [ -f composer.lock ]; then
			LOCK_HASH=$(grep -m 1 '"content-hash"' composer.lock | awk -F '"' '{print $4}')
		fi
		STAMP_FILE="vendor/composer/.lock-content-hash"
		INSTALLED_HASH=""
		if [ -f "$STAMP_FILE" ]; then
			INSTALLED_HASH=$(cat "$STAMP_FILE")
		fi
		if [ -z "$(ls -A 'vendor/' 2>/dev/null)" ] || [ "$LOCK_HASH" != "$INSTALLED_HASH" ]; then
			composer install --prefer-dist --no-progress --no-interaction
			if [ -n "$LOCK_HASH" ] && [ -d vendor/composer ]; then
				printf '%s' "$LOCK_HASH" > "$STAMP_FILE"
			fi
		fi
	fi

	# Display information about the current project
	# Or about an error in project initialization
	php bin/console -V

	if grep -q ^DATABASE_URL= .env; then
		echo 'Waiting for database to be ready...'
		DB_WAIT_ATTEMPTS=60
		ATTEMPTS_LEFT_TO_REACH_DATABASE=$DB_WAIT_ATTEMPTS
		# SENTRY_DSN= makes the SDK inert for THIS probe only. The retry loop boots the
		# full Symfony kernel each second, and until the `database` host resolves and
		# accepts connections every failed `SELECT 1` is captured by the bundle's error
		# listener — a flood of expected "could not translate host name" / connection
		# events on every container boot (cold `make app.dev`, deploy, worktree up).
		# These are liveness-probe failures, not application errors; suppressing them
		# here (in dev AND prod) costs no real error fidelity — a database that never
		# comes up still fails the loop, prints the error, and `exit 1`s the container.
		until [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ] || DATABASE_ERROR=$(SENTRY_DSN= php bin/console dbal:run-sql -q "SELECT 1" 2>&1); do
			if [ $? -eq 255 ]; then
				# If the Doctrine command exits with 255, an unrecoverable error occurred
				ATTEMPTS_LEFT_TO_REACH_DATABASE=0
				break
			fi
			sleep 1
			ATTEMPTS_LEFT_TO_REACH_DATABASE=$((ATTEMPTS_LEFT_TO_REACH_DATABASE - 1))
			echo "Still waiting for database to be ready... Or maybe the database is not reachable. $ATTEMPTS_LEFT_TO_REACH_DATABASE attempts left."
		done

		if [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ]; then
			# This container is about to exit 1. That is retryable, not terminal: every service
			# running this entrypoint has `restart: unless-stopped` (compose.yaml), so Docker
			# restarts it and the ${DB_WAIT_ATTEMPTS}-attempt wait above runs again — the stack
			# self-heals once the database is reachable, at the cost of a visible crash-loop in
			# the meantime (and of nothing paging anyone while it does — this line makes the
			# state legible to a log reader, not to an on-call rotation). Said explicitly here
			# because this is the one point in that loop nothing else observes: it runs before
			# Sentry's SDK boots, so an outage long enough to exhaust this loop is otherwise
			# silent until something greps container logs. Framed as attempts, not seconds: each
			# iteration also boots the Symfony kernel to run the probe, so wall time is strictly
			# more than ${DB_WAIT_ATTEMPTS}s.
			echo "✗ giving up after ${DB_WAIT_ATTEMPTS} attempts waiting for the database:"
			echo "$DATABASE_ERROR"
			echo '  Exiting 1 — restart: unless-stopped will retry this container automatically.'
			exit 1
		else
			echo 'The database is now ready and reachable'
		fi

		if [ "$(find ./migrations -iname '*.php' -print -quit)" ]; then
			php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
		fi
	fi

	echo 'PHP app ready!'
fi

exec docker-php-entrypoint "$@"
