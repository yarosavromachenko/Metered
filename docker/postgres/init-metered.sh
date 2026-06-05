#!/usr/bin/env bash
set -euo pipefail

# Runs once, on first start of an empty data directory.
#
# The test database is created here rather than by the test suite so that the
# integration and concurrency suites can open real, separate connections
# without racing each other to create it.
psql --username "${POSTGRES_USER}" --dbname "${POSTGRES_DB}" <<-SQL
    CREATE DATABASE metered_testing OWNER ${POSTGRES_USER};

    -- UUIDv7 arrives from the application, not from the database, but
    -- gen_random_uuid() is still useful in ad-hoc queries and fixtures.
    CREATE EXTENSION IF NOT EXISTS pgcrypto;
SQL
