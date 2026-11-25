#!/usr/bin/php
<?php
/*
 *  update-geoip.php - download the current DB-IP databases (free, CC BY 4.0, updated monthly):
 *    "IP to Country Lite" -> inc/lib/geoip/dbip-country-lite.mmdb (8 MB), always;
 *    "IP to City Lite"    -> inc/lib/geoip/dbip-city-lite.mmdb (~130 MB, a 60 MB download), when a board shows
 *                            regions of Ukraine (mod.php -> board -> Геолокація: детальна), when it's already
 *                            there, or with --city. When it's there, the site uses it for every lookup.
 *  Run it once a month from cron:
 *
 *    0 5 3 * *  cd /path/to/site && php tools/update-geoip.php
 *
 *  Options: --city (the city database too), --no-city (the country one only).
 */

require dirname(__FILE__) . '/inc/cli.php';

$want_city = in_array('--city', $argv) || (!in_array('--no-city', $argv) && (is_file(nichan_geoip_city_file()) || nichan_geo_detailed_anywhere()));

// Downloads one database into $target through files on disk (no 130 MB strings in memory). true when done.
function nichan_geoip_download($kind, $target) {
	foreach (array(date('Y-m'), date('Y-m', strtotime('first day of last month'))) as $month) {
		$url = "https://download.db-ip.com/free/dbip-$kind-lite-$month.mmdb.gz";
		echo "Downloading $url ... ";
		$gz = $target . '.download.gz';
		$tmp = $target . '.download';
		$ok = false;
		if (function_exists('curl_init')) {
			$fp = fopen($gz, 'wb');
			$ch = curl_init($url);
			curl_setopt_array($ch, array(CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 900, CURLOPT_CONNECTTIMEOUT => 20));
			$ok = curl_exec($ch) && curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200;
			curl_close($ch);
			fclose($fp);
		} else {
			$ok = @copy($url, $gz);
		}
		if (!$ok || filesize($gz) < 100000) {
			@unlink($gz);
			echo "not available\n";
			continue;
		}
		// unpack in pieces
		$in = gzopen($gz, 'rb');
		$out = fopen($tmp, 'wb');
		while (!gzeof($in))
			fwrite($out, gzread($in, 1048576));
		gzclose($in);
		fclose($out);
		@unlink($gz);
		// the metadata marker sits near the end of the file
		$fh = fopen($tmp, 'rb');
		fseek($fh, max(0, filesize($tmp) - 131072));
		$tail = fread($fh, 131072);
		fclose($fh);
		if (strpos($tail, "\xAB\xCD\xEFMaxMind.com") === false) {
			@unlink($tmp);
			echo "not a valid database\n";
			continue;
		}
		rename($tmp, $target);
		printf("done (%.1f MB)\n", filesize($target) / 1048576);
		return true;
	}
	return false;
}

$failed = false;
if (!nichan_geoip_download('country', nichan_geoip_file())) {
	echo "Could not update the country database; the old one is kept.\n";
	$failed = true;
}
if ($want_city && !nichan_geoip_download('city', nichan_geoip_city_file())) {
	echo "Could not update the city database; " . (is_file(nichan_geoip_city_file()) ? 'the old one is kept' : 'the country database is used') . ".\n";
	$failed = true;
}
exit($failed ? 1 : 0);
