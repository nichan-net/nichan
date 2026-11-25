#!/usr/bin/php
<?php
/*
 *  nichan-hash-passwords.php - replace the deletion passwords that old posts keep as typed with their
 *  keyed hashes (new posts are stored hashed already). Deleting one's own post keeps working.
 *  Safe to run again: hashed ones are skipped.
 *
 *    php tools/nichan-hash-passwords.php --dry-run   # only count
 *    php tools/nichan-hash-passwords.php
 */

require dirname(__FILE__) . '/inc/cli.php';

$dry = in_array('--dry-run', $argv);
$total = 0;
foreach (listBoards() as $b) {
	$query = query(sprintf("SELECT `id`, `password` FROM ``posts_%s`` WHERE `password` IS NOT NULL AND `password` <> '' AND NOT (`password` LIKE '#%%' AND CHAR_LENGTH(`password`) = 20)", $b['uri'])) or error(db_error());
	$rows = $query->fetchAll(PDO::FETCH_ASSOC);
	if (!$dry) {
		$update = prepare(sprintf('UPDATE ``posts_%s`` SET `password` = :hash WHERE `id` = :id', $b['uri']));
		foreach ($rows as $row) {
			$update->bindValue(':hash', nichan_post_password_hash($row['password']));
			$update->bindValue(':id', (int)$row['id'], PDO::PARAM_INT);
			$update->execute() or error(db_error($update));
		}
	}
	$total += count($rows);
	echo "/{$b['uri']}/: " . count($rows) . ($dry ? ' to hash' : ' hashed') . "\n";
}
echo ($dry ? 'Would hash' : 'Hashed') . " $total passwords.\n";
