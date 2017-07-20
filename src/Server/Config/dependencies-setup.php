<?php

use Mcustiel\Creature\SingletonLazyCreator;
use Mcustiel\DependencyInjection\DependencyInjectionService;
use Mcustiel\Phiremock\Common\Http\Implementation\GuzzleConnection;
use Mcustiel\Phiremock\Common\Http\RemoteConnectionInterface;
use Mcustiel\Phiremock\Common\Utils\RequestBuilderFactory;
use Mcustiel\Phiremock\Server\Actions\AddExpectationAction;
use Mcustiel\Phiremock\Server\Actions\ClearExpectationsAction;
use Mcustiel\Phiremock\Server\Actions\ClearScenariosAction;
use Mcustiel\Phiremock\Server\Actions\CountRequestsAction;
use Mcustiel\Phiremock\Server\Actions\ListExpectationsAction;
use Mcustiel\Phiremock\Server\Actions\ResetRequestsCountAction;
use Mcustiel\Phiremock\Server\Actions\SearchRequestAction;
use Mcustiel\Phiremock\Server\Actions\StoreRequestAction;
use Mcustiel\Phiremock\Server\Actions\VerifyRequestFound;
use Mcustiel\Phiremock\Server\Config\Actions;
use Mcustiel\Phiremock\Server\Config\InputSources;
use Mcustiel\Phiremock\Server\Config\Matchers;
use Mcustiel\Phiremock\Server\Config\RouterConfig;
use Mcustiel\Phiremock\Server\Http\Implementation\ReactPhpServer;
use Mcustiel\Phiremock\Server\Http\InputSources\UrlFromPath;
use Mcustiel\Phiremock\Server\Model\Implementation\ExpectationAutoStorage;
use Mcustiel\Phiremock\Server\Model\Implementation\RequestAutoStorage;
use Mcustiel\Phiremock\Server\Model\Implementation\ScenarioAutoStorage;
use Mcustiel\Phiremock\Server\Phiremock;
use Mcustiel\Phiremock\Server\Utils\FileExpectationsLoader;
use Mcustiel\Phiremock\Server\Utils\HomePathService;
use Mcustiel\Phiremock\Server\Utils\RequestExpectationComparator;
use Mcustiel\Phiremock\Server\Utils\ResponseStrategyFactory;
use Mcustiel\Phiremock\Server\Utils\Strategies\HttpResponseStrategy;
use Mcustiel\Phiremock\Server\Utils\Strategies\ProxyResponseStrategy;
use Mcustiel\Phiremock\Server\Utils\Strategies\RegexResponseStrategy;
use Mcustiel\PowerRoute\Actions\ServerError;
use Mcustiel\PowerRoute\Common\Conditions\ConditionsMatcherFactory;
use Mcustiel\PowerRoute\Common\Factories\ActionFactory;
use Mcustiel\PowerRoute\Common\Factories\InputSourceFactory;
use Mcustiel\PowerRoute\Common\Factories\MatcherFactory;
use Mcustiel\PowerRoute\InputSources\Body;
use Mcustiel\PowerRoute\InputSources\Header;
use Mcustiel\PowerRoute\InputSources\Method;
use Mcustiel\PowerRoute\Matchers\CaseInsensitiveEquals;
use Mcustiel\PowerRoute\Matchers\Contains as ContainsMatcher;
use Mcustiel\PowerRoute\Matchers\Equals;
use Mcustiel\PowerRoute\Matchers\RegExp as RegExpMatcher;
use Mcustiel\PowerRoute\PowerRoute;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

$di = new DependencyInjectionService();

$di->register('logger', function () {
    // create a log channel
    $log = new Logger('stdoutLogger');
    $log->pushHandler(new StreamHandler(STDOUT, LOG_LEVEL));

    return $log;
});

$di->register(RemoteConnectionInterface::class, function () {
    return new GuzzleConnection(new GuzzleHttp\Client());
});

$di->register(HttpResponseStrategy::class, function () use ($di) {
    return new HttpResponseStrategy($di->get('logger'));
});

$di->register(RegexResponseStrategy::class, function () use ($di) {
    return new RegexResponseStrategy($di->get('logger'));
});

$di->register(ProxyResponseStrategy::class, function () use ($di) {
    return new ProxyResponseStrategy(
        $di->get(RemoteConnectionInterface::class),
        $di->get('logger')
    );
});

$di->register('responseStrategyFactory', function () use ($di) {
    return new ResponseStrategyFactory($di);
});

$di->register('config', function () {
    return RouterConfig::get();
});

$di->register('homePathService', function () {
    return new HomePathService();
});

$di->register('server', function () use ($di) {
    $server = new ReactPhpServer($di->get('logger'));

    return $server;
});

$di->register('application', function () use ($di) {
    return new Phiremock($di->get('router'), $di->get('logger'));
});

$di->register('expectationStorage', function () {
    return new ExpectationAutoStorage();
});

$di->register('requestStorage', function () {
    return new RequestAutoStorage();
});

$di->register('scenarioStorage', function () {
    return new ScenarioAutoStorage();
});

$di->register('requestExpectationComparator', function () use ($di) {
    return new RequestExpectationComparator(
        $di->get('matcherFactory'),
        $di->get('inputSourceFactory'),
        $di->get('scenarioStorage'),
        $di->get('logger')
    );
});

$di->register('requestBuilder', function () {
    return RequestBuilderFactory::createRequestBuilder();
});

$di->register('fileExpectationsLoader', function () use ($di) {
    return new FileExpectationsLoader(
        $di->get('requestBuilder'),
        $di->get('expectationStorage'),
        $di->get('logger')
    );
});

$di->register('conditionsMatcherFactory', function () use ($di) {
    return new ConditionsMatcherFactory(
        $di->get('inputSourceFactory'),
        $di->get('matcherFactory')
    );
});

$di->register('inputSourceFactory', function () {
    return new InputSourceFactory([
        InputSources::METHOD => new SingletonLazyCreator(Method::class),
        InputSources::URL    => new SingletonLazyCreator(UrlFromPath::class),
        InputSources::HEADER => new SingletonLazyCreator(Header::class),
        InputSources::BODY   => new SingletonLazyCreator(Body::class),
    ]);
});

$di->register('router', function () use ($di) {
    return new PowerRoute(
        $di->get('config'),
        $di->get('actionFactory'),
        $di->get('conditionsMatcherFactory')
    );
});

$di->register('matcherFactory', function () {
    return new MatcherFactory([
        Matchers::EQUAL_TO    => new SingletonLazyCreator(Equals::class),
        Matchers::MATCHES     => new SingletonLazyCreator(RegExpMatcher::class),
        Matchers::SAME_STRING => new SingletonLazyCreator(CaseInsensitiveEquals::class),
        Matchers::CONTAINS    => new SingletonLazyCreator(ContainsMatcher::class),
    ]);
});

$di->register('actionFactory', function () use ($di) {
    return new ActionFactory([
        Actions::ADD_EXPECTATION => new SingletonLazyCreator(
            AddExpectationAction::class,
            [
                $di->get('requestBuilder'),
                $di->get('expectationStorage'),
                $di->get('logger'),
            ]
        ),
        Actions::LIST_EXPECTATIONS => new SingletonLazyCreator(
            ListExpectationsAction::class,
            [$di->get('expectationStorage')]
        ),
        Actions::CLEAR_EXPECTATIONS => new SingletonLazyCreator(
            ClearExpectationsAction::class,
            [$di->get('expectationStorage')]
        ),
        Actions::SERVER_ERROR    => new SingletonLazyCreator(ServerError::class),
        Actions::CLEAR_SCENARIOS => new SingletonLazyCreator(
            ClearScenariosAction::class,
            [$di->get('scenarioStorage')]
        ),
        Actions::CHECK_EXPECTATIONS => new SingletonLazyCreator(
            SearchRequestAction::class,
            [
                $di->get('expectationStorage'),
                $di->get('requestExpectationComparator'),
                $di->get('logger'),
            ]
        ),
        Actions::VERIFY_EXPECTATIONS => new SingletonLazyCreator(
            VerifyRequestFound::class,
            [
                $di->get('scenarioStorage'),
                $di->get('logger'),
                $di->get('responseStrategyFactory'),
            ]
        ),
        Actions::COUNT_REQUESTS => new SingletonLazyCreator(
            CountRequestsAction::class,
            [
                $di->get('requestBuilder'),
                $di->get('requestStorage'),
                $di->get('requestExpectationComparator'),
                $di->get('logger'),
            ]
        ),
        Actions::RESET_COUNT => new SingletonLazyCreator(
            ResetRequestsCountAction::class,
            [$di->get('requestStorage')]
        ),
        Actions::STORE_REQUEST => new SingletonLazyCreator(
            StoreRequestAction::class,
            [$di->get('requestStorage')]
        ),
    ]);
});

return $di;
