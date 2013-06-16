<?php
/**
 * QuickBooks Web Connector ('QBWC') web service
 *
 * @package    QuickBooks
 * @subpackage WebConnector
 * @copyright  2013 IATSTUTI
 * @author     Michael Dyrynda <michael@iatstuti.net>
 */
namespace Qbwc;

use IATSTUTI\DB;
use IATSTUTI\Log\File;
use Qbwc\Ticket;

class WebService
{
    /**#@+
     * Private class property
     *
     * @access private
     */

    /** Database instance */
    private $db;

    /** Logger instance */
    private $logger;

    /** Ticket instance */
    private $ticket;

    /** Server version */
    private $server_version = 2.0;

    /** Client version */
    private $client_version = 'O:2.0';

    /** Remote address */
    private $remote_address;

    /**
     * Because we expose all class methods with __call, define methods that
     * should actually be kept private
     */
    private $private_methods = array(
        'completionPercent',
    );
    /**#@-*/

    const URI          = 'http://developer.intuit.com';
    const TOKEN_STRING = 'youdaboss';
    const TOKEN_PASS   = 'bastardos';

    /**#@+
     * QuickBooks error code
     */
    const QB_ERROR_WHEN_PARSING = '0x80040400';
    const QB_COULDNT_ACCESS_QB  = '0x80040401';
    const QB_UNEXPECTED_ERROR   = '0x80040402';
    /**#@-*/

    /**#@+
     * @access public
     */


    public function __construct(DB $db, LoggerInterface $logger)
    {
        if ( isset($_SERVER['HTTP_CLIENT_IP']) ) {
            $remote_address = $_SERVER['HTTP_CLIENT_IP'];
        } elseif ( isset($_SERVER['HTTP_X_FORWARDED_FOR']) ) {
            $remote_address = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $remote_address = $_SERVER['REMOTE_ADDR'];
        }

        $this->remote_address = ip2long($remote_address);
        $this->db             = $db;
        $this->ticket         = new Ticket($this->db, $this->remote_address);

        try {
            $this->logger = $logger;
        } catch ( Exception $e ) {
            throw new \Exception($e->getMessage());
        }
    }


    private function authenticate($strUserName, $strPassword)
    {
        $ticket = $this->ticket->getNew();

        if ( strcmp($strPassword, self::TOKEN_PASS) !== 0 ) {
            $this->logger->info(
                sprintf(
                    '%s - Authentication failed %s:%s',
                    __FUNCTION__,
                    $strUserName,
                    $strPassword
                )
            );

            return array( $ticket, 'nvu', );
        }

        $this->logger->info(
            sprintf(
                '%s - Authenticated user %s from %s with ticket %s',
                __FUNCTION__,
                $strUserName,
                $_SERVER['REMOTE_ADDR'],
                $ticket
            )
        );

        return array( $ticket, '', );
    }


    private function clientVersion()
    {
        $this->logger->info(
            sprintf(
                'Current supported client version %s',
                __FUNCTION__,
                $this->client_version
            )
        );

        return $this->client_version;
    }


    private function closeConnection($ticket)
    {
        try {
            $return = $this->ticket->finish($ticket);

            if ( $return === false ) {
                $this->logger->info('No rows updated when closing connection');
            } else {
                $this->logger->info(
                    sprintf(
                        'Completed operations for ticket %s in %ds',
                        $ticket,
                        $return
                    )
                );
            }

            return 'Update complete';
        } catch ( Exception $e ) {
            $this->logger->error($e->getMessage());

            return $e->getMessage();
        }
    }


    private function connectionError($ticket, $hresult, $message)
    {
        $this->logger->error(
            sprintf(
                '%s - Session %s encountered a QuickBooks error %s: %s',
                __FUNCTION__,
                $ticket,
                $hresult,
                $message
            )
        );

        $this->closeConnection($ticket);

        return 'Done';
    }


    private function getInteractiveURL()
    {

    }


    private function getLastError($ticket)
    {
        $last_error = $this->db->selectFirst(
            '*',
            'qbwc_error',
            'ticket = ' . $this->db->escape($ticket) . ' AND ' .
            'remote_address = ' . $this->db->escape($this->remote_address),
            'date_added DESC'
        );

        if ( isset($last_error['message']) ) {
            $this->log->info(
                sprintf(
                    '%s - Last error for ticket %s: %s',
                    __FUNCTION__,
                    $ticket,
                    $last_error['message']
                )
            );

            return $last_error['message'];
        }

        return '';
    }


    private function serverVersion()
    {
        $this->logger->info(
            sprintf(
                '%s - current version %s',
                __FUNCTION__,
                $this->server_version
            )
        );

        return $this->server_version;
    }


    private function interactiveDone()
    {
        $this->logger->info(sprintf('%s - interactive mode complete', __FUNCTION__));

        return 'Done';
    }


    private function interactiveRejected()
    {
        $this->logger->info(sprintf('%s - function not implemented', __FUNCTION__));

        return 'Not implemented';
    }


    private function receiveResponseXML(
        $ticket,
        $response,
        $hresult,
        $message
    ) {
        if ( ! $this->ticket->exists($ticket) ) {
            $this->logger->error(
                sprintf(
                    '%s - Supplied ticket %s is invalid, exiting',
                    __FUNCTION__,
                    $ticket
                )
            );

            $this->setError($ticket, 'Invalid token specified');

            return -100;
        }

        $fh = fopen('/tmp/qbwc_sendResponseXML.txt', 'a+');
        fwrite($fh, $response);
        fwrite($fh, var_export(simplexml_load_string($response), true));
        fclose($fh);
    }


    private function sendRequestXML(
        $ticket,
        $strHCPResponse,
        $strCompanyFileName,
        $qbXMLCountry,
        $qbXMLMajorVers,
        $qbXMLMinorVers
    ) {

        $fh = fopen('/tmp/qbwc_sendRequestXML.txt', 'a+');
        fwrite($fh, $response);
        fwrite($fh, var_export(simplexml_load_string($strHCPResponse), true));
        fclose($fh);
    }


    private function setError($ticket, $message)
    {
        $this->db->insert(
            'qbwc_error',
            array(
                'ticket'         => $this->db->escape($ticket),
                'message'        => $this->db->escape($message),
                'remote_address' => $this->db->escape($this->remote_address),
                '!date_added'    => 'NOW()',
            )
        );

        return $this->db->countAffected() > 0;
    }


    private function completionPercent($ticket, $percentage = null)
    {
        if ( ! is_null($percentage) ) {
            $this->db->update(
                'qbwc_error',
                array( 'percentage_complete' => $percentage, ),
                'ticket = ' . $this->db->escape($ticket) . ' AND ' .
                'remote_address = ' . $this->db->escape($this->remote_address)
            );

            return $this->db->countAffected() > 0;
        } else {
            $response = $this->db->selectFirst(
                array( 'percentage_complete', ),
                'qbwc_error',
                'ticket = ' . $this->db->escape($ticket) . ' AND ' .
                'remote_address = ' . $this->db->escape($this->remote_address)
            );

            if ( count($response) == 0 ) {
                $this->logger->error('Could not locate data for ticket ' . $ticket);

                return false;
            }

            return $response['percentage_complete'];
        }
    }


    public function __call($name, $args)
    {
        $this->logger->info(
            sprintf(
                'Incoming request from %s',
                long2ip($this->remote_address)
            )
        );

        if ( ! method_exists($this, $name) ) {
            $this->logger->warning(
                sprintf(
                    'Call to undefined method "%s" with params: %s',
                    $name,
                    join(', ', $args)
                )
            );

            throw new \SoapFault(
                '-101',
                'ERROR: Call to undefined method ' . $name
            );
        } else {
            $this->logger->info(
                sprintf(
                    '%s - called with %d parameter%s: %s',
                    $name,
                    count($args),
                    count($args) != 1 ? 's' : null,
                    join(', ', $args)
                )
            );

            return call_user_func_array(array( $this, $name, ), $args);
        }
    }


}

