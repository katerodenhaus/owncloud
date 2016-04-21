<?php
include '/var/www/html/owncloud/lib/base.php';
include '/var/www/html/owncloud/lib/private/installer.php';
try {
    OC_App::enable(OC_App::cleanAppId("168707"), null);
    OC_JSON::success();
    file_put_contents("/var/www/html/owncloud/.calendar_installed", "");
} catch (Exception $e) {
    OC_Log::write('core', $e->getMessage(), OC_Log::ERROR);
    OC_JSON::error(array("data" => array("message" => $e->getMessage()) ));
}