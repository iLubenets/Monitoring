<?php

/*
 * This file is part of the Monolog package.
 *
 * (c) Igor Lubenets <il@prostobank.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Prosto\FrameworkBundle\MonologHandler;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\MissingExtensionException;
use Monolog\Logger;

/**
 * Logs to ProstoMonitoring.
 *
 * @link https://github.com/iLubenets/Monitoring
 * @author il <il@prostobank.com>
 */
class ProstoMonitoringHandler extends AbstractProcessingHandler
{
    private $udpConnection = null;
    private $httpConnection = null;
    private $scheme = null;
    private $host = null;
    private $port = null;
    private $acceptedSchemes = array('http', 'udp');

    /**
     * Create a Cube handler
     *
     * @throws UnexpectedValueException when given url is not a valid url.
     * A valid url must consists of three parts : protocol://host:port
     * Only valid protocol used by Cube are http and udp
     */
    public function __construct($url, $level = Logger::DEBUG, $bubble = true)
    {
        $urlInfos = parse_url($url);

        if (!isset($urlInfos['scheme']) || !isset($urlInfos['host']) || !isset($urlInfos['port'])) {
            throw new \UnexpectedValueException('URL "'.$url.'" is not valid');
        }

        if (!in_array($urlInfos['scheme'], $this->acceptedSchemes)) {
            throw new \UnexpectedValueException(
                'Invalid protocol (' . $urlInfos['scheme']  . ').'
                . ' Valid options are ' . implode(', ', $this->acceptedSchemes));
        }

        $this->scheme = $urlInfos['scheme'];
        $this->host = $urlInfos['host'];
        $this->port = $urlInfos['port'];

        parent::__construct($level, $bubble);
    }

    /**
     * Establish a connection to an UDP socket
     *
     * @throws LogicException when unable to connect to the socket
     */
    protected function connectUdp()
    {
        if (!extension_loaded('sockets')) {
            throw new MissingExtensionException('The sockets extension is required to use udp URLs with the CubeHandler');
        }

        $this->udpConnection = socket_create(AF_INET, SOCK_DGRAM, 0);
        if (!$this->udpConnection) {
            throw new \LogicException('Unable to create a socket');
        }

        if (!socket_connect($this->udpConnection, $this->host, $this->port)) {
            throw new \LogicException('Unable to connect to the socket at ' . $this->host . ':' . $this->port);
        }
    }

    /**
     * Establish a connection to a http server
     */
    protected function connectHttp()
    {
        if (!extension_loaded('curl')) {
            throw new \LogicException('The curl extension is needed to use http URLs with the CubeHandler');
        }

        $this->httpConnection = curl_init('http://'.$this->host.':'.$this->port);

        if (!$this->httpConnection) {
            throw new \LogicException('Unable to connect to ' . $this->host . ':' . $this->port);
        }

        curl_setopt($this->httpConnection, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($this->httpConnection, CURLOPT_RETURNTRANSFER, true);
    }

    /**
     * {@inheritdoc}
     */
    protected function write(array $record)
    {
        $data = array('registered' => $record['datetime']->format('Y-m-d\TH:i:s.u'));
        unset($record['datetime']);
        if (isset($record['context']['type'])) {
            $data['event'] = $record['context']['type'];
            unset($record['context']['type']);
        } else {
            $data['event'] = $record['channel'];
            unset($record['channel']);
        }
        $data['level'] = $record['level'];
        unset($record['level']);
        // Other fields
        $data['message'] = $record;

        $this->{'write'.$this->scheme}(json_encode($data));
    }

    private function writeUdp($data)
    {
        if (!$this->udpConnection) {
            $this->connectUdp();
        }

        socket_send($this->udpConnection, $data, strlen($data), 0);
    }

    private function writeHttp($data)
    {
        if (!$this->httpConnection) {
            $this->connectHttp();
        }

        curl_setopt($this->httpConnection, CURLOPT_POSTFIELDS, $data );
        curl_setopt($this->httpConnection, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen('['.$data.']'))
        );

        return curl_exec($this->httpConnection);
    }
}