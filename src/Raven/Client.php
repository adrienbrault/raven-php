<?php

namespace Raven;

use Guzzle\Common\Collection;
use Guzzle\Service\Client as GuzzleClient;
use Guzzle\Service\Command\Factory\MapFactory;
use Raven\Plugin\SentryAuthPlugin;
use Raven\Request\Factory\ExceptionFactory;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @author Adrien Brault <adrien.brault@gmail.com>
 *
 * @method array capture(array $parameters = array())
 */
class Client extends GuzzleClient
{
    const VERSION = '1.0.0-dev';
    const PROTOCOL_VERSION = 4;

    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';
    const LEVEL_FATAL = 'fatal';

    private $exceptionFactory;

    public function __construct(array $config = array())
    {
        parent::__construct(
            sprintf(
                '{protocol}://{host}%s{+path}api/{project_id}/',
                isset($config['port']) ? sprintf(':%d', $config['port']) : ''
            ),
            $config
        );

        if (!$this->getDefaultOption('headers/User-Agent')) {
            $this->setDefaultOption(
                'headers/User-Agent',
                sprintf('raven-php/' . Client::VERSION)
            );
        }

        $this->setCommandFactory(new MapFactory(array(
            'capture' => 'Raven\Command\CaptureCommand',
        )));
        $this->addSubscriber(new SentryAuthPlugin(
            $this->getConfig('public_key'),
            $this->getConfig('secret_key'),
            self::PROTOCOL_VERSION,
            $this->getDefaultOption('headers/User-Agent')
        ));

        $this->exceptionFactory = isset($config['exception_factory'])
            ? $config['exception_factory']
            : new ExceptionFactory()
        ;
    }

    public static function create($config = array())
    {
        return new static(static::resolveAndValidateConfig($config));
    }

    private static function resolveAndValidateConfig(array $config)
    {
        $dsnParser = new DsnParser();
        if (isset($config['dsn'])) {
            $config = array_merge($config, $dsnParser->parse($config['dsn']));
        }

        $resolver = new OptionsResolver();
        $resolver->setRequired(array(
            'public_key',
            'secret_key',
            'project_id',
            'protocol',
            'host',
            'path',
            'port',
        ));
        $resolver->setOptional(array(
            'dsn',
            'exception_factory',
            self::REQUEST_OPTIONS,
            self::CURL_OPTIONS,
        ));

        $resolver->setDefaults(array(
            'protocol' => 'https',
            'host' => 'app.getsentry.com',
            'path' => '/',
            'port' => null,
        ));

        $resolver->setAllowedTypes(array(
            'public_key' => 'string',
            'secret_key' => 'string',
            'project_id' => 'string',
            'protocol' => 'string',
            'host' => 'string',
            'path' => 'string',
            'port' => array('null', 'integer'),
        ));
        $resolver->setAllowedValues(array(
            'protocol' => array('https', 'http'),
        ));

        $config = $resolver->resolve($config);

        return $config;
    }

    public function captureException(\Exception $e, array $parameters = array())
    {
        $exception = $this->exceptionFactory->create($e);

        $parameters['message'] = $e->getMessage();
        $parameters['sentry.interfaces.Exception'] = $exception;

        if ($e instanceof \ErrorException) {
            $parameters['level'] = $this->getSeverityLevel($e->getSeverity());
        }

        return $this->capture($parameters);
    }

    private function getSeverityLevel($severity)
    {
        switch ($severity) {
            case E_ERROR:              return self::LEVEL_ERROR;
            case E_WARNING:            return self::LEVEL_WARNING;
            case E_PARSE:              return self::LEVEL_ERROR;
            case E_NOTICE:             return self::LEVEL_INFO;
            case E_CORE_ERROR:         return self::LEVEL_ERROR;
            case E_CORE_WARNING:       return self::LEVEL_WARNING;
            case E_COMPILE_ERROR:      return self::LEVEL_ERROR;
            case E_COMPILE_WARNING:    return self::LEVEL_WARNING;
            case E_USER_ERROR:         return self::LEVEL_ERROR;
            case E_USER_WARNING:       return self::LEVEL_WARNING;
            case E_USER_NOTICE:        return self::LEVEL_INFO;
            case E_STRICT:             return self::LEVEL_INFO;
            case E_RECOVERABLE_ERROR:  return self::LEVEL_ERROR;
            case E_DEPRECATED:         return self::LEVEL_WARNING;
            case E_USER_DEPRECATED:    return self::LEVEL_WARNING;
        }

        return self::LEVEL_ERROR;
    }
}
