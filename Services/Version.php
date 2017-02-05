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


use SN\ToolboxBundle\Helper\CommandHelper;

class Version
{

    private $settings;
    private $root;

    public function __construct($settings, $root)
    {
        $this->settings = $settings;
        $this->root     = $root;
    }

    /**
     * @return bool|array
     */
    protected function getSettings()
    {
        $deploy_file = sprintf("%s/../deploy.json", $this->root);
        if (file_exists($deploy_file)) {
            $json = file_get_contents(sprintf($deploy_file, $this->root));
            $json = json_decode($json, true);

            return $json;
        }

        return false;
    }

    /**
     * @return string
     */
    public function getVersion()
    {

        $settings = $this->getSettings();
        if ($settings === false) {
            return "__DEV__";
        }

        return $settings["version"];

    }

    /**
     * @param bool $short
     * @return string
     */
    public function getCommit($short = true)
    {
        // Will try first `git reb-parse HEAD`
        if ($short) {
            $cmd = sprintf("git rev-parse --short HEAD");
        } else {
            $cmd = sprintf("git rev-parse HEAD");
        }
        $commit = CommandHelper::executeCommand($cmd);

        if (strpos($commit, "command not found") === false) {
            return $commit;
        }

        // If git is not installed, it will try to get deploy.json informations
        $settings = $this->getSettings();
        
        if ($settings === false) {
            return null;
        }

        if ($short) {
            return $settings["commit"];
        } else {
            return $settings["commit_long"];
        }

    }

    /**
     * @return \DateTime|null
     */
    public function getTime()
    {
        $settings = $this->getSettings();
        if ($settings === false) {
            return null;
        }

        $date = new \DateTime();
        $date->setTimestamp($settings["timestamp"]);

        return $date;
    }

}