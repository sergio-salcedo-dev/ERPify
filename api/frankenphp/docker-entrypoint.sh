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

	# The image-storage root the application refuses to invent for itself. It is provisioned here rather
	# than in the image, and ONLY when something is genuinely mounted at its parent — compared by device
	# id, since a mounted volume and the container's own filesystem never share one.
	#
	# The condition is the whole point. `lazy_root_creation` only defers the library's own mkdir to the
	# first write, so what actually makes an unmounted volume fail loudly is the application's guard on the
	# root existing; provision it unconditionally and that guard stops meaning anything. A root created
	# inside the container's writable layer makes every deletion answer success — a confirmed erasure of
	# bytes that are somewhere else entirely — which is the one failure this module exists to refuse.
	#
	# All three branches speak. The third one — the path is not there at all — used to be the `if`'s
	# implicit else and printed nothing, so a mistyped mount left a container that boots clean, passes its
	# healthcheck, and fails every image operation hours later with no line connecting the two.
	if [ -n "${STORAGE_LOCAL_PATH:-}" ]; then
		if [ ! -d "$STORAGE_LOCAL_PATH" ]; then
			echo "! $STORAGE_LOCAL_PATH does not exist — leaving the image storage root unprovisioned."
		elif [ "$(stat -c '%d' "$STORAGE_LOCAL_PATH")" != "$(stat -c '%d' "$(dirname "$STORAGE_LOCAL_PATH")")" ]; then
			mkdir -p "$STORAGE_LOCAL_PATH/images"
		else
			echo "! $STORAGE_LOCAL_PATH is not a mount point — leaving the image storage root unprovisioned."
		fi
	fi

	# The evidence-storage root must be a volume genuinely mounted at the path, or the container refuses to
	# start: a root on the container's writable layer passes the boot probe below, holds evidence that dies
	# with the container, and answers "done" to every later deletion of bytes that live elsewhere. A mount
	# point is a directory on another device than its parent. `staging` and `final` are created side by side
	# on that volume, since a promotion between them is only a single-filesystem move while they share one.
	if [ -n "${DOCUMENTS_STORAGE_PATH:-}" ]; then
		if [ ! -d "$DOCUMENTS_STORAGE_PATH" ]; then
			echo "! $DOCUMENTS_STORAGE_PATH does not exist — refusing to start."
			exit 1
		fi
		if [ "$(stat -c '%d' "$DOCUMENTS_STORAGE_PATH")" = "$(stat -c '%d' "$(dirname "$DOCUMENTS_STORAGE_PATH")")" ]; then
			echo "! $DOCUMENTS_STORAGE_PATH is not a mount point — refusing to start."
			exit 1
		fi
		mkdir -p "$DOCUMENTS_STORAGE_PATH/staging" "$DOCUMENTS_STORAGE_PATH/final"
	fi

	# The document storage boot guard: a container whose storage cannot stage, promote and delete an object must
	# not start, because every promotion would then fail after its commit (st_dev equality is not proof — a move
	# fails with EXDEV across two mount points of one filesystem). Run by every container that touches the
	# volume — php writes it, the workers' sweeps delete from it. Sentry is inert for the probe, like the
	# database wait below: a refusal here is a boot failure, already loud.
	if [ -n "${DOCUMENTS_STORAGE_PATH:-}" ]; then
		if ! SENTRY_DSN='' php bin/console documents:storage:probe --no-interaction; then
			echo "! The document storage refused the boot probe — refusing to start."
			exit 1
		fi
	fi

	# The upload spool guard: FrankenPHP spools every request body it reads to upload_tmp_dir, and an evidence
	# PUT's body is a plaintext original. The directory must be a tmpfs (memory, never persistent storage) the
	# server user can write to; PHP itself would fall back to /tmp on the writable layer with only a notice.
	if [ "$1" = 'frankenphp' ]; then
		SPOOL_DIR=$(php -r 'echo ini_get("upload_tmp_dir");')
		if [ -z "$SPOOL_DIR" ] || [ ! -d "$SPOOL_DIR" ]; then
			echo "! upload_tmp_dir ($SPOOL_DIR) is not a directory — refusing to start."
			exit 1
		fi
		if [ "$(stat -f -c '%T' "$SPOOL_DIR")" != 'tmpfs' ]; then
			echo "! upload_tmp_dir ($SPOOL_DIR) is not a tmpfs — plaintext spools would reach disk; refusing to start."
			exit 1
		fi
		SPOOL_PROBE="$SPOOL_DIR/.boot-probe.$$"
		if ! (: >"$SPOOL_PROBE") 2>/dev/null || ! rm -f "$SPOOL_PROBE"; then
			echo "! upload_tmp_dir ($SPOOL_DIR) is not writable by $(id -un) — refusing to start."
			exit 1
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
		until [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ] || DATABASE_ERROR=$(SENTRY_DSN='' php bin/console dbal:run-sql -q "SELECT 1" 2>&1); do
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

	# `async` gets its after-commit guarantee from resolving to the Doctrine transport on the connection the
	# use cases write through, and MESSENGER_TRANSPORT_DSN is a per-service deployment value that no file in
	# the repository can pin. Asked of THIS process, once, here: every service running this entrypoint
	# (php, messenger_worker, scheduler_worker) answers for the DSN it was started with, and so does a
	# one-off `docker compose run … bin/console`. A refusal exits 1 like the database wait above, so the
	# container exits, `restart: unless-stopped` retries it and `docker compose up --wait` fails. The cause
	# is printed by the command itself, just above: a bad DSN and a kernel that cannot boot both end here.
	if ! php bin/console event:transport:assert-transactional --no-interaction; then
		echo '✗ refusing to boot: the "async" transport check failed (see above).'
		echo '  "async" must resolve to the Doctrine transport on the application connection.'
		exit 1
	fi

	echo 'PHP app ready!'
fi

# The container log lives on the host (Docker's json-file driver), so nothing inside the container can read what
# it holds. Where CONTAINER_LOG_MIRROR is set — compose.dev.yaml only, never production — everything the server
# writes to stdout and stderr is also appended to that file, through a FIFO and `tee`, so a containment gate can
# assert from inside the container that a value never reached the container log. The mirror is emptied at each
# start, and FrankenPHP stays PID 1 (the shell execs into it), so signals and the healthcheck are unchanged.
if [ "$1" = 'frankenphp' ] && [ -n "${CONTAINER_LOG_MIRROR:-}" ]; then
	: >"$CONTAINER_LOG_MIRROR"
	MIRROR_FIFO="$(mktemp -u)"
	mkfifo "$MIRROR_FIFO"
	tee -a "$CONTAINER_LOG_MIRROR" <"$MIRROR_FIFO" &
	exec docker-php-entrypoint "$@" >"$MIRROR_FIFO" 2>&1
fi

exec docker-php-entrypoint "$@"
