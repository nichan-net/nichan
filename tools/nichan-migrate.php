#!/usr/bin/php
<?php
/*
 *  nichan-migrate.php - bring a database (e.g. a fresh dump of the old live site) up to the schema
 *  this version of the site expects. Safe to run any number of times.
 *
 *  The site also does this by itself on the first page view after an update, so running this is
 *  only needed when you want to see what happens (or convert a dump before uploading the site).
 *
 *  Usage: php tools/nichan-migrate.php
 */

require dirname(__FILE__) . '/inc/cli.php';

nichan_migrate(true);
@file_put_contents('tmp/cache/nichan-schema-' . NICHAN_SCHEMA_VERSION, date('c'));
echo "Done.\n";
