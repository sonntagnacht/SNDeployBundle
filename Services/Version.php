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

    public function __construct($root)
    {
        $this->root     = $root;
        $this->settings = array(
            "version"     => "__DEV__",
            "commit"      => "",
            "commit_long" => "",
            "timestamp"   => time()
        );

        $deploy_file = sprintf("%s/../deploy.json", $this->root);
        if (file_exists($deploy_file)) {
            $json           = file_get_contents($deploy_file);
            $this->settings = json_decode($json, true);
        } else {
            $commitShort = sprintf("git rev-parse --short HEAD");
            $commitLong  = sprintf("git rev-parse HEAD");

            $commit = CommandHelper::executeCommand($commitShort);
            if (strpos($commit, "command not found") === false) {
                $this->settings["commit"] = $commit;
            }

            $commit = CommandHelper::executeCommand($commitLong);
            if (strpos($commit, "command not found") === false) {
                $this->settings["commit_long"] = $commit;
            }
        }
    }

    /**
     * @return string
     */
    public function getVersion()
    {
        return $this->settings["version"];
    }

    /**
     * @param bool $short
     * @return string
     */
    public function getCommit($short = true)
    {
        if ($short) {
            return $this->settings["commit"];
        } else {
            return $this->settings["commit_long"];
        }

    }

    /**
     * @return \DateTime|null
     */
    public function getTime()
    {
        $date = new \DateTime();
        $date->setTimestamp($this->settings["timestamp"]);

        return $date;
    }

}