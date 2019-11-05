<?php

namespace Mcustiel\Phiremock\Server\Config;

class InputSources
{
    const METHOD = 'method';
    const URL = 'url';
    const HEADER = 'header';
    const BODY = 'body';

    const VALID_INPUT_SOURCES = [
        self::METHOD,
        self::URL,
        self::HEADER,
        self::BODY,
    ];

    public static function isValidInputSource(string $inputSource): bool
    {
        return \in_array($inputSource, self::VALID_INPUT_SOURCES, true);
    }
}
