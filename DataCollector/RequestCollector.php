<?php

namespace SN\DeployBundle\DataCollector;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

class RequestCollector extends DataCollector
{
    protected $settings;
    protected $root;

    public function __construct($sn_deploy, $root)
    {
        $this->settings = $sn_deploy;
        $this->root     = $root;
    }

    public function getName()
    {
        return 'sn_deploy.request_collector';
    }

    public function collect(Request $request, Response $response, \Exception $exception = null)
    {
        $this->data = array(
            'method'                   => $request->getMethod(),
            'acceptable_content_types' => $request->getAcceptableContentTypes()
        );
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

}