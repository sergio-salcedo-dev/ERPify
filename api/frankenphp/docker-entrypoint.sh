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
		ATTEMPTS_LEFT_TO_REACH_DATABASE=60
		until [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ] || DATABASE_ERROR=$(php bin/console dbal:run-sql -q "SELECT 1" 2>&1); do
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
			echo 'The database is not up or not reachable:'
			echo "$DATABASE_ERROR"
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
