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

use Doctrine\DBAL\Configuration;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;
use SplFileObject;


/* Authentiction log.
 * Two message types are supported:
 * - logIntrinsicLoaAuthentication (LoA 1 Authentication)
 * - logSecondFactorAuthentication (Authentication involving a second factor)
 * See:
 * - https://github.com/SURFnet/Stepup-Gateway/blob/develop/src/Surfnet/StepupGateway/GatewayBundle/Monolog/Logger/AuthenticationLogger.php
 * - https://github.com/SURFnet/Stepup-saml-bundle/blob/develop/src/Monolog/SamlAuthenticationLogger.php

 * - second_factor_id
 * - second_factor_type
 * - institution:
 * - authentication_result: Result of 2nd factor validation ((NONE, OK, FAILED)
 * - resulting_loa: LoA URI
 * - identity_id: Subject NameID from remote IdP (SURFconext)
 * - authenticating_idp: SAML EntityID of the IdP (AuthenticatingAuthority)
 * - requesting_sp: SAML EntityID of the SP
 * - datetime: Timestamp format "Y-m-d\\TH:i:sP"
 * - sari: The requestID of SAML request from the SP
 * - request_id: Stepup request ID (X-Stepup-Request-Id)

 */


class AuthnLogCommand extends Command
{
    // Number of lines between stat file updates
    static private $STAT_FILE_WRITE_INTERVAL=1000;

    // Map JSON log field -> database column
    static private $DB_MAP = [
        //'timestamp' => 'timestamp',
        '_ctxt_second_factor_id' => 'sndf_id', // GUID
        '_ctxt_second_factor_type' => 'sndf_type', // tiqr, sms, yubikey
        '_ctxt_institution' => 'institution',    // schecHomeOrganizaton
        '_ctxt_authentication_result' => 'result',  // NONE, OK, FAILED
        '_ctxt_resulting_loa' => 'loa', // loa uri
        '_ctxt_identity_id' => 'nameid', // SURFconext unspecified nameid
        '_ctxt_authenticating_idp' => 'idp_enityid', // SAML EnityID of IdP
        '_ctxt_requesting_sp' => 'sp_enitytid', // SAML EnityID of SP
        //'_ctxt_sari' => 'sari', // SAML RequestID from SP
        '_request_id' => 'request_id', // Stepup HTTP request ID (X-Stepup-Request-Id)
        '_ctxt_datetime' => 'ts', // Timestamp format "Y-m-d\\TH:i:sP" (ISO)
    ];


    protected function configure()
    {
        $this->setName('authnlog');
        $this->setDescription('Process Stepup-authentication log');

        $this->addArgument('file', InputArgument::REQUIRED);

        $this->addOption(
            'config',
            'c',
            InputOption::VALUE_REQUIRED,
            'Configuration file',
            'config_authnlog.json'
        );
    }


    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /* @var $app StepupToolsApplication */
        $app=$this->getApplication();
        /* @var $logger LoggerInterface */
        $logger = $app->getLogger();

        // Load configuration
        $config_filename = $input->getOption('config');
        $logger->info("Config file: ${config_filename}");
        $app->loadConfig($config_filename);

        // When configured, use a stat file for keeping progress
        $statFilename=$app->getConfigValue('stat_file');
        if (is_string($statFilename)) {
            $logger->info('Stat file: '.$statFilename);
        }

        // Get authentication log file to parse
        $input_filename = $input->getArgument('file');
        $logger->info("Input file: ${input_filename}");
        $file = new SplFileObject($input_filename, 'rb');

        // Connect to DB
        $dbConn = DriverManager::getConnection($app->getConfigValue('database'));
        $dbConn->connect();

        $this->processFile($file, $dbConn, $logger, $statFilename);
    }


    private function processFile(SplFileObject $auditLogFile, Connection $dbConn, LoggerInterface $logger, $statFilename)
    {
        // Create prepared statement for the REPLACE (INSERT)
        // Replace is a mysql extension
        $cols = array_values(AuthnLogCommand::$DB_MAP);
        $cols[] = 'id';    // ID column for fingerprint
        $sql = 'REPLACE INTO `stepup` ('. implode(',', $cols).') VALUES (:'.implode(',:', $cols).');';
        $logger->debug('Preparing statement: '.$sql);
        $dbStatement = $dbConn->prepare($sql);

        // Statistics
        $line_count = 0;
        $line_error_count = 0;
        $line_ignore_count = 0;
        $line_added = 0;
        $line_existed = 0;

        // Read state
        $line_no = 0;   // Current line number
        $line_pos = 0;  // Current file offset

        $statInterval = self::$STAT_FILE_WRITE_INTERVAL;
        $auditLogFilename=$auditLogFile->getPathname();

        // Get previous parse location from stat file
        if ( is_string($statFilename) && file_exists($statFilename) ) {
            $logger->debug('Processing stat file');
            $logger->info("Writing progress to stat file '${statFilename}' every $statInterval lines");
            $stat = json_decode(file_get_contents($statFilename), true);
            if ( isset($stat['file']) && isset($stat['line']) && isset($stat['pos']) && ($stat['file']==$auditLogFilename) ) {
                $line_no=$stat['line'];
                $line_pos=$stat['pos'];
                $auditLogFile->fseek($line_pos, SEEK_SET);
                $logger->info("Using position '${line_pos}' and line number '${line_no}' from stat file '${statFilename}'");
            }
        }

        try {
            while (!$auditLogFile->eof()) {

                $line_pos = $auditLogFile->ftell();
                // Update stat file
                if ( is_string($statFilename) && ($line_count % $statInterval) == 0) {
                    $logger->debug('Writing stat file');
                    file_put_contents($statFilename, json_encode(['file'=>$auditLogFilename, 'line'=>$line_no, 'pos'=>$line_pos]));
                }

                // Read line from file
                $line = $auditLogFile->fgets();
                if (false === $line) {
                    break;
                }
                $line_no++;
                $line_count++;

                $line = trim($line);
                if (strlen($line) == 0) {
                    $line_ignore_count++;
                    continue;
                }

                // Look for first '{', that is where the JSON should start, ignore anything before that.
                $json_start_pos = strpos($line, '{');
                if (false === $json_start_pos) {
                    $line_error_count++;
                    $logger->notice("Ignoring line. No JSON at line #${line_no}");
                    $logger->debug("Offending line: '${line}'");
                    continue; // Next line
                }

                // Found JSON start character '{'
                $line_json = json_decode(substr($line, $json_start_pos));
                if (null === $line_json) {
                    $line_error_count++;
                    $line_pos += $json_start_pos; // Offset where JSON should start
                    $json_error = json_last_error_msg();
                    $logger->notice("Ignoring line. Error decoding JSON at line #${line_no}; json_error=${json_error}");
                    $logger->debug($line);
                }

                /* Calculate 128-bit md5 fingerprint for the log message
                   Assume that this fingerprint is unique for the message:
                   - request_id (source StepUp), sari (source SP), and timestamp provide uniqueness
                   - Chance of collision with md5 algorithm is low. (< 10^-18 for 2.6 * 10^10 values)
                     https://en.wikipedia.org/wiki/Birthday_problem#Probability_table
                   Use json_encode to create a stable string from the message
                */
                $line_fingerprint = md5(json_encode($line_json), true);

                // Create parameters to insert into the DB
                $parameters = array( 'id' => $line_fingerprint );
                foreach (AuthnLogCommand::$DB_MAP as $json_name => $db_name)
                    @$parameters[$db_name] = $line_json->{$json_name};

                // Insert into DB
                $res=$dbStatement->execute($parameters);
                $affectedRows = $dbStatement->rowCount();
                if ($affectedRows == 1) {
                    $line_added++;
                }
                if ($affectedRows == 2) {
                    // REPLACE will DELETE and then UPDATE if line exists, resulting in a row count of 2
                    $line_existed++;
                }
            }

            // Done: Update stat file
            if ( is_string($statFilename) && ($line_count % $statInterval) == 0) {
                $logger->debug('Writing stat file');
                file_put_contents($statFilename, json_encode(['file'=>$auditLogFilename, 'line'=>$line_no-1, 'pos'=>$line_pos]));
                $logger->info("Wrote stat file '${statFilename}' for '${auditLogFilename} with pos=${line_pos}");
            }

        } catch (\Exception $e) {
            $logger->error("Exception near line number ${line_no}, file position ${line_pos}");

            throw $e;
        }
        finally {
            $logger->info("Read ${line_count} line(s)");
            $logger->info("Added ${line_added} new line(s) to the database");
            if ($line_error_count > 0)
                $logger->error("Skipped ${line_error_count} line(s) because of parse errors");
            else
                $logger->info("Skipped ${line_error_count} line(s) because of parse errors");

            $logger->info("Ignored ${line_ignore_count} empty line(s)");
            $logger->info("${line_existed} line(s) already existed in the database");

            $logger->info('Current read position: '.$auditLogFile->ftell());
            $logger->info('Current line number: '.$line_no);
        }
    }

}