<?php

namespace Mcustiel\Phiremock\Server\Tests\V1;

use AcceptanceTester;
use Codeception\Configuration;

class BinaryContentCest
{
    public function _before(AcceptanceTester $I)
    {
        $I->sendDELETE('/__phiremock/expectations');
    }

    public function shouldCreateAnExpectationWithBinaryResponse(AcceptanceTester $I)
    {
        $responseContents = file_get_contents(Configuration::dataDir() . 'fixtures/silhouette-1444982_640.png');

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST(
            '/__phiremock/expectations',
            $I->getPhiremockRequest([
                'request' => [
                    'url' => ['isEqualTo' => '/show-me-the-image-now'],
                ],
                'response' => [
                    'headers' => [
                        'Content-Type'     => 'image/jpeg',
                    ],
                    'body' => 'phiremock.base64:' . base64_encode($responseContents),
                ],
            ])
        );

        $I->sendGET('/show-me-the-image-now');
        $I->seeResponseCodeIs(200);
        $I->seeHttpHeader('Content-Type', 'image/jpeg');
        $responseBody = $I->grabResponse();
        $I->assertEquals($responseContents, $responseBody);
    }
}
