<?php
try {
    $config = json_decode(file_get_contents('/opt/css/config/databases.json'), TRUE);

    if ($config === FALSE) {
        throw new Exception("ERROR: Cannot open database config or it does not exist");
    } else {
        if (array_key_exists("owncloud", $config['databases'])) {
            // Set the script variables
            putenv("DATABASE={$config['databases']['owncloud']['host']}");
            putenv("DB_USER={$config['databases']['owncloud']['user']}");
            putenv("DB_PASS={$config['databases']['owncloud']['password']}");

            // Now update the config
            $hostname = gethostname();

            $oc_config = json_decode(file_get_contents(__DIR__ . "/config-base.json"), TRUE);
            $oc_config['system']['trusted_domains'] = [0 => $hostname];
            $oc_config['system']['overwrite.cli.url'] = [$hostname => ""];
            $oc_config['apps']['user_saml']['saml_sp_source'] = $hostname;

            // Generate config file and Facter facts
            file_put_contents(__DIR__ . "/config.json", json_encode($oc_config));
            file_put_contents("/etc/facter/facts.d/owncloud.txt", "owncloud_db={$config['databases']['owncloud']['host']}\n");
            file_put_contents("/etc/facter/facts.d/owncloud.txt", "owncloud_dbuser={$config['databases']['owncloud']['user']}\n", FILE_APPEND);
            file_put_contents("/etc/facter/facts.d/owncloud.txt", "owncloud_dbpass={$config['databases']['owncloud']['password']}\n", FILE_APPEND);
            file_put_contents("/etc/facter/facts.d/owncloud.txt", "is_owncloud=true\n", FILE_APPEND);

            // Install and apply configurations
            echo exec(__DIR__ . "/setup_owncloud.sh");
        }
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
