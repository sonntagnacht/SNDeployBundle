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
     * @return null|string
     */
    public function getCommit()
    {

        $settings = $this->getSettings();
        if ($settings === false) {
            return null;
        }

        return $settings["commit"];

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