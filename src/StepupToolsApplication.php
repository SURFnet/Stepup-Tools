<?php
/**
 * Copyright 2015 SURFnet bv
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Surfnet\StepupTools;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;
use Surfnet\StepupTools\AuthnLogCommand;


class StepupToolsApplication extends Application
{
    private $logger;
    private $appPath;
    private $configuration;

    /** Get path of console application's directory
     * @return string
     */
    public function getAppPath()
    {
        return $this->appPath;
    }

    function __construct(LoggerInterface $logger, $name, $version, $appPath)
    {
        parent::__construct($name, $version);

        $this->logger = $logger;
        $this->appPath = $appPath;

        $this->add( new AuthnLogCommand() );
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }


    public function loadConfig($configFileName)
    {
        $config_contents = file_get_contents($configFileName);
        if (false === $config_contents)
            throw new \Exception("Error reading file: ${configFileName}");
        if (false === strpos($config_contents, '{'))
            throw new \Exception("No JSON in file: ${configFileName}");
        $config = json_decode( $config_contents, true );
        if (NULL === $config) {
            $msg = json_last_error_msg();
            throw new \Exception("Error decoding JSON: ${configFileName}; JSON Error: ${msg}");
        }

        $this->configuration = $config;
    }

    public function getConfigValue($key)
    {
        if (isset($this->configuration[$key]))
            return $this->configuration[$key];
        return NULL;
    }

}