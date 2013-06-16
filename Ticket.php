<?php
/**
 * QuickBooks Web Connector ('QBWC') ticket class
 *
 * @package    QuickBooks
 * @subpackage WebConnector
 * @copyright  2013 IATSTUTI
 * @author     Michael Dyrynda <michael@iatstuti.net>
 */
namespace Qbwc;

// use IATSTUTI\DB;

class Ticket
{
    /**#@+
     * Private class property
     *
     * @access private
     */

    /** Database instance */
    private $db;

    /** Remote address */
    private $remote_address;
    /**#@-*/


    public function __construct(DB $db, $remote_address)
    {
        $this->db             = $db;
        $this->remote_address = $remote_address;
    }


    public function getNew()
    {
        $salt = time() . ip2long($_SERVER['REMOTE_ADDR']);

        $ticket = md5(uniqid() . $salt);

        if ( $this->exists($ticket) ) {
            return $this->getNew();
        }

        $this->start($ticket);

        return $ticket;
    }


    public function getExisting($ticket)
    {
        return $this->db->selectFirst(
            '*',
            'qbwc_ticket',
            'ticket = ' . $this->db->escape($ticket) . ' AND ' .
            'remote_address = ' . $this->db->escape($this->remote_address)
        );
    }


    public function exists($ticket)
    {
        return count($this->getExisting($ticket)) > 0;
    }


    public function finish($ticket)
    {
        $ticket_data = $this->getExisting($ticket);

        if ( count($ticket_data) == 0 ) {
            throw new \Exception(sprintf('Ticket %s does not exist', $ticket));
        }

        $this->db->update(
            'qbwc_ticket',
            array( '!date_end' => 'NOW()', ),
            'ticket = ' . $this->db->escape($ticket) . ' AND ' .
            'remote_address = ' . $this->db->escape($this->remote_address)
        );

        if ( $this->db->countAffected() == 0 ) {
            return false;
        }

        $ticket_data = $this->getExisting($ticket);

        $start = strtotime($ticket_data['date_start']);
        $end   = strtotime($ticket_data['date_end']);

        return array( $ticket_data, $start, $end, ($end - $start), );
    }


    private function start($ticket)
    {
        return $this->db->insert(
            'qbwc_ticket',
            array(
                'ticket'            => $ticket,
                'remote_address'    => $this->remote_address,
                '!date_start'       => 'NOW()',
                '!date_last_access' => 'NOW()',
            )
        );
    }


}

