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

namespace Mcustiel\Phiremock\Server\Http\Matchers;

use Mcustiel\PowerRoute\Matchers\MatcherInterface;
use Psr\Log\LoggerInterface;

class JsonObjectsEquals implements MatcherInterface
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     *
     * @see \Mcustiel\PowerRoute\Matchers\MatcherInterface::match()
     */
    public function match($value, $argument = null)
    {
        if (is_string($value)) {
            $requestValue = $this->getParsedValue($value);
        } else {
            $requestValue = $value;
        }
        $configValue = $this->decodeJson($argument);

        if (!is_array($requestValue) || !is_array($configValue)) {
            return false;
        }

        return $this->areRecursivelyEquals($requestValue, $configValue);
    }

    /**
     * @param string $value
     *
     * @return mixed
     */
    private function decodeJson($value)
    {
        $decodedValue = json_decode($value, true);
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new \InvalidArgumentException('JSON parsing error: ' . json_last_error_msg());
        }

        return $decodedValue;
    }

    private function areRecursivelyEquals(array $array1, array $array2)
    {
        foreach ($array1 as $key => $value1) {
            if (!array_key_exists($key, $array2)) {
                return false;
            }
            if (!$this->haveTheSameTypeAndValue($value1, $array2[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param mixed $value1
     * @param mixed $value2
     *
     * @return bool
     */
    private function haveTheSameTypeAndValue($value1, $value2)
    {
        if (gettype($value1) !== gettype($value2)) {
            return false;
        }

        return $this->haveTheSameValue($value1, $value2);
    }

    /**
     * @param mixed $value1
     * @param mixed $value2
     *
     * @return bool
     */
    private function haveTheSameValue($value1, $value2)
    {
        if (is_array($value1)) {
            if (!$this->areRecursivelyEquals($value1, $value2)) {
                return false;
            }
        } else {
            if ($value1 !== $value2) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $value
     *
     * @return mixed
     */
    private function getParsedValue($value)
    {
        try {
            $requestValue = $this->decodeJson($value);
        } catch (\InvalidArgumentException $e) {
            $requestValue = $value;
            $this->logger->warning('Invalid json received in request: ' . $value);
        }

        return $requestValue;
    }
}
