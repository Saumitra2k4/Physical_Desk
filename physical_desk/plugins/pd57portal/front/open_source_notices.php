<?php
include('../../../inc/includes.php');
require_once dirname(__DIR__) . '/inc/portal.php';
Session::checkLoginUser();
pd57_layout_start('Open Source Notices');
echo '<section class="panel"><p class="eyebrow">Legal information</p><h1>Open Source Notices</h1><p>Physical Desk / PD57 is built on open-source service-management components.</p><h2>GLPI</h2><p>PD57 uses GLPI, developed by Teclib and contributors. GLPI is licensed under the GNU General Public License, version 3 or later.</p><h2>Other components</h2><p>Additional bundled components remain subject to their respective open-source licenses and notices. Their source headers and licence files are retained with the distribution.</p><p><a class="button-secondary" href="/plugins/pd57portal/front/index.php">Return to Physical Desk</a></p></section>';
pd57_layout_end();
