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

namespace Mcustiel\Phiremock\Server\Utils;

use Mcustiel\Phiremock\Domain\Expectation;
use Mcustiel\Phiremock\Domain\RequestConditions;
use Mcustiel\Phiremock\Server\Config\InputSources;
use Mcustiel\Phiremock\Server\Http\InputSources\InputSourceLocator;
use Mcustiel\Phiremock\Server\Http\Matchers\MatcherLocator;
use Mcustiel\Phiremock\Server\Model\ScenarioStorage;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

class RequestExpectationComparator
{
    /** @var MatcherLocator */
    private $matcherLocator;
    /** @var InputSourceLocator */
    private $inputSourceLocator;
    /** @var ScenarioStorage */
    private $scenarioStorage;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        MatcherLocator $matcherLocator,
        InputSourceLocator $inputSourceLocator,
        ScenarioStorage $scenarioStorage,
        LoggerInterface $logger
    ) {
        $this->matcherLocator = $matcherLocator;
        $this->inputSourceLocator = $inputSourceLocator;
        $this->scenarioStorage = $scenarioStorage;
        $this->logger = $logger;
    }

    public function equals(ServerRequestInterface $httpRequest, Expectation $expectation): bool
    {
        $this->logger->debug('Checking if request matches an expectation');

        if (!$this->isExpectedScenarioState($expectation)) {
            return false;
        }

        $expectedRequest = $expectation->getRequest();

        $atLeastOneExecution = $this->compareRequestParts($httpRequest, $expectedRequest);

        if (null !== $atLeastOneExecution && $expectedRequest->getHeaders()) {
            $this->logger->debug('Checking headers against expectation');

            return $this->requestHeadersMatchExpectation($httpRequest, $expectedRequest);
        }

        $this->logger->debug('Matches? ' . ((bool) $atLeastOneExecution ? 'yes' : 'no'));

        return (bool) $atLeastOneExecution;
    }

    private function compareRequestParts(ServerRequestInterface $httpRequest, RequestConditions $expectedRequest): ?bool
    {
        $atLeastOneExecution = false;
        $requestParts = ['Method', 'Url', 'Body'];

        foreach ($requestParts as $requestPart) {
            $getter = "get{$requestPart}";
            $matcher = "request{$requestPart}MatchesExpectation";
            if ($expectedRequest->{$getter}()) {
                $this->logger->debug("Checking {$requestPart} against expectation");
                if (!$this->{$matcher}($httpRequest, $expectedRequest)) {
                    return null;
                }
                $atLeastOneExecution = true;
            }
        }

        return $atLeastOneExecution;
    }

    private function isExpectedScenarioState(Expectation $expectation): bool
    {
        if ($expectation->getRequest()->hasScenarioState()) {
            $this->checkScenarioNameOrThrowException($expectation);
            $this->logger->debug('Checking scenario state again expectation');
            $scenarioState = $this->scenarioStorage->getScenarioState(
                $expectation->getScenarioName()
            );
            if (!$expectation->getRequest()->getScenarioState()->equals($scenarioState)) {
                return false;
            }
        }

        return true;
    }

    /** @throws \RuntimeException */
    private function checkScenarioNameOrThrowException(Expectation $expectation)
    {
        if (!$expectation->hasScenarioName()) {
            throw new \InvalidArgumentException('Expecting scenario state without specifying scenario name');
        }
    }

    private function requestMethodMatchesExpectation(ServerRequestInterface $httpRequest, RequestConditions $expectedRequest): bool
    {
        $inputSource = $this->inputSourceLocator->locate(InputSources::METHOD);
        $matcher = $this->matcherLocator->locate($expectedRequest->getMethod()->getMatcher()->asString());

        return $matcher->match(
            $inputSource->getValue($httpRequest),
            $expectedRequest->getMethod()->getValue()->asString()
        );
    }

    private function requestUrlMatchesExpectation(ServerRequestInterface $httpRequest, RequestConditions $expectedRequest): bool
    {
        $inputSource = $this->inputSourceLocator->locate(InputSources::URL);
        $matcher = $this->matcherLocator->locate($expectedRequest->getUrl()->getMatcher()->asString());

        return $matcher->match(
            $inputSource->getValue($httpRequest),
            $expectedRequest->getUrl()->getValue()->asString()
        );
    }

    private function requestBodyMatchesExpectation(ServerRequestInterface $httpRequest, RequestConditions $expectedRequest): bool
    {
        $inputSource = $this->inputSourceLocator->locate(InputSources::BODY);
        $matcher = $this->matcherLocator->locate(
            $expectedRequest->getBody()->getMatcher()->asString()
        );

        return $matcher->match(
            $inputSource->getValue($httpRequest),
            $expectedRequest->getBody()->getValue()->asString()
        );
    }

    private function requestHeadersMatchExpectation(ServerRequestInterface $httpRequest, RequestConditions $expectedRequest): bool
    {
        $inputSource = $this->inputSourceLocator->locate(InputSources::HEADER);
        foreach ($expectedRequest->getHeaders() as $header => $headerCondition) {
            $matcher = $this->matcherLocator->locate(
                $headerCondition->getMatcher()->asString()
            );

            $matches = $matcher->match(
                $inputSource->getValue($httpRequest, $header->asString()),
                $headerCondition->getValue()->asString()
            );
            if (!$matches) {
                return false;
            }
        }

        return true;
    }
}
