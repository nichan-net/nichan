<?php
// Зразок для install.php. Заповнена конфігурація зберігається у файлі instance-config.php.
defined('TINYBOARD') or exit;

$config['db']['server'] = '{{db_host}}';
$config['db']['dsn'] = '{{db_dsn}}';
$config['db']['database'] = '{{db_name}}';
$config['db']['user'] = '{{db_user}}';
$config['db']['password'] = '{{db_password}}';
$config['db']['prefix'] = '';
$config['cookies']['salt'] = '{{cookie_salt}}';
$config['secure_trip_salt'] = '{{trip_salt}}';
$config['root'] = '/';
$config['nichan_public_url'] = '{{public_url}}';
$config['domain'] = $config['nichan_public_url'];
$config['locale'] = 'uk';

$config['max_body'] = 18000;
$config['force_image_op'] = true;
$config['force_body_op'] = false;
$config['flood_time'] = 0;
$config['flood_time_ip'] = 1;
$config['flood_time_same'] = 1;

$config['spam']['hidden_inputs_min'] = 2;
$config['spam']['hidden_inputs_max'] = 4;
$config['spam']['hidden_inputs_max_pass'] = 100;
$config['spam']['hidden_inputs_expire'] = 1080000;
$config['spam']['unicode'] = false;
$config['thumb_method'] = 'gd';
$config['gnu_md5'] = false;
$config['global_message'] = '';
