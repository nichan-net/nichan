<?php 
/* This file is dedicated to the public domain; you may do as you wish with it. */
$footer = @file_get_contents(__DIR__ . '/tmp/player-footer.html');
if ($footer === false) {
    require_once __DIR__ . '/inc/functions.php';
    $footer = nichan_footer($config);
}
$v = @(string)$_GET['v'];
// only the boards' own videos (not any address, not javascript:)
if (!preg_match('~^/?[a-z0-9_]+/src/[0-9a-z_-]+\.(webm|mp4)$~i', $v))
	$v = '';
$t = @(string)$_GET['t'];
$loop = @(boolean)$_GET['loop'];

$params = '?v=' . urlencode($v) . '&amp;t=' . urlencode($t);
?><!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo htmlspecialchars($t); ?></title>
    <link rel="stylesheet" href="stylesheets/webm/playerstyle.css">
    <link rel="stylesheet" href="stylesheets/footer.css">
    <script src="js/webm-settings.js"></script>
    <script src="js/webm/playersettings.js"></script>
</head>
<body>
    <div id="playerheader">
        <a id="loop0" href="<?php echo $params; ?>&amp;loop=0"<?php if (!$loop) echo ' style="font-weight: bold"'; ?>>[play once]</a>
        <a id="loop1" href="<?php echo $params; ?>&amp;loop=1"<?php if ($loop) echo ' style="font-weight: bold"'; ?>>[loop]</a>
    </div>
    <div id="playercontent">
        <video controls<?php if ($loop) echo ' loop'; ?> src="<?php echo htmlspecialchars($v); ?>">
            Your browser does not support HTML5 video. <a href="<?php echo htmlspecialchars($v); ?>">[Download]</a>
        </video>
    </div>
    <?= $footer ?>
</body>
</html>
