<?php
// Run hourly with cron. Posting only reads the last successful snapshot.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$url = 'https://check.torproject.org/torbulkexitlist';
$ch = curl_init($url);
curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FAILONERROR => true,
	CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20));
$body = curl_exec($ch);
if (!is_string($body) || curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) {
	fwrite(STDERR, "Could not fetch the Tor Project exit list.\n");
	exit(1);
}
$addresses = array();
foreach (preg_split('/\R/', trim($body)) as $line) {
	$ip = trim($line);
	if ($ip === '' || $ip[0] === '#') continue;
	if (!filter_var($ip, FILTER_VALIDATE_IP)) {
		fwrite(STDERR, "Unexpected entry in the Tor exit list.\n");
		exit(1);
	}
	$addresses[inet_ntop(inet_pton($ip))] = true;
}
if (!$addresses) { fwrite(STDERR, "Empty Tor exit list.\n"); exit(1); }
$dir = dirname(__DIR__) . '/tmp';
$temp = tempnam($dir, 'tor-exits-');
$data = "\n" . implode("\n", array_keys($addresses)) . "\n";
if (!$temp || file_put_contents($temp, $data) !== strlen($data) || !chmod($temp, 0644)
	|| !rename($temp, $dir . '/tor-exits.txt')) {
	if ($temp) unlink($temp);
	fwrite(STDERR, "Could not publish the Tor exit list.\n");
	exit(1);
}
