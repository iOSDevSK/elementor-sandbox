#!/bin/bash
# Fingerprint of the REAL site (everything the sandbox must never change): posts and
# their meta (workspace copies excluded), options, terms, users, Elementor CSS files.
# Take one before a demo session and one after; `diff` must be empty except the
# Elementor library term counts.
#   EDS_CONTAINER=h2e-rt-sb-wp-1 tests/fingerprint.sh > before.txt
C=${EDS_CONTAINER:-h2e-rt-sb-wp-1}
docker cp "$(dirname "$0")/fingerprint.php" "$C":/tmp/eds-fingerprint.php >/dev/null
docker exec -u 33 -e HOME=/tmp "$C" wp eval-file /tmp/eds-fingerprint.php 2>&1
