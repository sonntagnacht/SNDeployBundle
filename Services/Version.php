<?php
/**
 * BugNerd
 * Created by PhpStorm.
 * File: Version.php
 * User: thomas
 * Date: 02.02.17
 * Time: 17:14
 */

namespace SN\DeployBundle\Services;


class Version
{

    private $settings;
    private $root;

    public function __construct($settings, $root)
    {
        $this->settings = $settings;
        $this->root     = $root;
    }

    public function getVersion()
    {
        $deploy_file = "%s/../deploy.json";
        if(file_exists($deploy_file)) {
            $json = file_get_contents(sprintf($deploy_file, $this->root));
            $json = json_decode($json, true);
        }

        return "__DEV__";
    }

}