<?php
/**
 * This file is part of Phiremock.
 *
 * Phiremock is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Phiremock is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Phiremock.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Mcustiel\Phiremock\Server\Http\Implementation;

use Mcustiel\Phiremock\Common\StringStream;
use Mcustiel\Phiremock\Server\Cli\Options\HostInterface;
use Mcustiel\Phiremock\Server\Cli\Options\Port;
use Mcustiel\Phiremock\Server\Http\RequestHandlerInterface;
use Mcustiel\Phiremock\Server\Http\ServerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\Factory as EventLoop;
use React\Http\Response as ReactResponse;
use React\Promise\Promise;
use React\Socket\Server as ReactSocket;

class ReactPhpServer implements ServerInterface
{
    /** @var \Mcustiel\Phiremock\Server\Http\RequestHandlerInterface */
    private $requestHandler;

    /** @var \React\EventLoop\LoopInterface */
    private $loop;

    /** @var \React\Socket\Server */
    private $socket;

    /** @var \React\Http\Server */
    private $http;

    /** @var \Psr\Log\LoggerInterface */
    private $logger;

    public function __construct(RequestHandlerInterface $requestHandler, LoggerInterface $logger)
    {
        $this->loop = EventLoop::create();
        $this->logger = $logger;
        $this->requestHandler = $requestHandler;
    }

    public function listen(HostInterface $host, Port $port)
    {
        $serverClass = $this->getReactServerClass();
        $this->http = new $serverClass(
            function (ServerRequestInterface $request) {
                return $this->createRequestManager($request);
            }
        );

        $listenConfig = "{$host->asString()}:{$port->asInt()}";
        $this->logger->info("Phiremock http server listening on {$listenConfig}");
        $this->socket = new ReactSocket($listenConfig, $this->loop);
        $this->http->listen($this->socket);

        // Dispatch pending signals periodically
        if (\function_exists('pcntl_signal_dispatch')) {
            $this->loop->addPeriodicTimer(0.5, function () {
                pcntl_signal_dispatch();
            });
        }
        $this->loop->run();
    }

    public function shutdown()
    {
        $this->loop->stop();
    }

    /** @return \React\Http\Server|\React\Http\StreamingServer */
    private function getReactServerClass()
    {
        if (class_exists('\React\Http\StreamingServer')) {
            return '\React\Http\StreamingServer';
        }

        return '\React\Http\Server';
    }

    /**
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    private function onRequest(ServerRequestInterface $request)
    {
        $start = microtime(true);
        $psrResponse = $this->requestHandler->dispatch($request);
        $this->logger->debug('Processing took ' . number_format((microtime(true) - $start) * 1000, 3) . ' milliseconds');

        return $psrResponse;
    }

    /**
     * @param ServerRequestInterface $request
     *
     * @return \React\Promise\Promise
     */
    private function createRequestManager(ServerRequestInterface $request)
    {
        return new Promise(function ($resolve, $reject) use ($request) {
            $bodyStream = '';

            $request->getBody()->on('data', function ($data) use (&$bodyStream, $request) {
                $bodyStream .= $data;
            });
            $request->getBody()->on('end', function () use ($resolve, $request, &$bodyStream) {
                $response = $this->onRequest($request->withBody(new StringStream($bodyStream)));
                $resolve($response);
            });
            $request->getBody()->on('error', function (\Exception $exception) use ($resolve) {
                $response = new ReactResponse(
                    400,
                    ['Content-Type' => 'text/plain'],
                    'An error occured while reading: ' . $exception->getMessage()
                );
                $resolve($response);
            });
        });
    }
}
