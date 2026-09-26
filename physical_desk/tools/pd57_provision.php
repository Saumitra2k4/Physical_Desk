<?php
require_once dirname(__DIR__) . '/src/Glpi/Application/ResourcesChecker.php';
(new \Glpi\Application\ResourcesChecker(dirname(__DIR__)))->checkResources();
require_once dirname(__DIR__) . '/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

// Init admin session
$user = new User();
if (!$user->getFromDBbyName('glpi')) {
    echo "ERROR: Admin user 'glpi' not found!" . PHP_EOL;
    exit(1);
}
$auth = new Auth();
$auth->auth_succeded = true;
$auth->user = $user;
Session::init($auth);
Session::changeProfile(4); // Super-Admin

require_once GLPI_ROOT . '/plugins/pd57auth/inc/provisioner.php';
$res = pd57auth_provision_prototype_accounts();
foreach ($res as $login => $info) {
    echo "Provisioned: $login -> ID={$info['id']}, Profile={$info['profile']}, Status={$info['status']}" . PHP_EOL;
}