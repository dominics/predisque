<?php

namespace Predisque;

use IteratorAggregate;
use Predis\Command\CommandInterface;
use Predis\Command\RawCommand;
use Predis\Command\ScriptCommand;
use Predis\Configuration\OptionsInterface;
use Predis\Connection\AggregateConnectionInterface;
use Predis\Connection\ConnectionInterface;
use Predis\Connection\ParametersInterface;
use Predis\Monitor\Consumer as MonitorConsumer;
use Predis\NotSupportedException;
use Predis\Pipeline\Pipeline;
use Predis\PubSub\Consumer as PubSubConsumer;
use Predis\Response\ErrorInterface as ErrorResponseInterface;
use Predis\Response\ResponseInterface;
use Predis\Response\ServerException;
use Predis\Transaction\MultiExec as MultiExecTransaction;
use Predisque\Configuration\Options;

/**
 * Disque client
 *
 * Based on Predis, and reusing a lot of the implementation. Unfortunately, we have to copy rather a lot
 * of the Client class, because there's no easy way to counteract at-method phpdoc tags. In other words,
 * unless we refuse to implement Predis\ClientInterface, we'll get method completion for commands that
 * won't work with Disque (eww).
 *
 * Our magic methods are documented on Predisque\ClientInterface
 *
 * @see |Predis\Client
 */
class Client /* extends \Predis\Client */ implements ClientInterface, IteratorAggregate
{
    const VERSION = '0.0.1';

    protected $connection;
    protected $options;
    private $profile;

    /**
     * @param mixed $parameters Connection parameters for one or more servers.
     * @param mixed $options    Options to configure some behaviours of the client.
     */
    public function __construct($parameters = null, $options = null)
    {
        $this->options = $this->createOptions($options ?: []);
        $this->connection = $this->createConnection($parameters ?: []);
        $this->profile = $this->options->profile;
    }

    /**
     * Creates a new instance of Predis\Configuration\Options from different
     * types of arguments or simply returns the passed argument if it is an
     * instance of Predis\Configuration\OptionsInterface.
     *
     * @param mixed $options Client options.
     * @throws \InvalidArgumentException
     * @return OptionsInterface
     */
    protected function createOptions($options)
    {
        if (is_array($options)) {
            return new Options($options);
        }

        if ($options instanceof OptionsInterface) {
            return $options;
        }

        throw new \InvalidArgumentException('Invalid type for client options.');
    }

    /**
     * Creates single or aggregate connections from different types of arguments
     * (string, array) or returns the passed argument if it is an instance of a
     * class implementing Predis\Connection\ConnectionInterface.
     *
     * Accepted types for connection parameters are:
     *
     *  - Instance of Predis\Connection\ConnectionInterface.
     *  - Instance of Predis\Connection\ParametersInterface.
     *  - Array
     *  - String
     *  - Callable
     *
     * @param mixed $parameters Connection parameters or connection instance.
     * @throws \InvalidArgumentException
     * @return ConnectionInterface
     */
    protected function createConnection($parameters)
    {
        if ($parameters instanceof ConnectionInterface) {
            return $parameters;
        }

        if ($parameters instanceof ParametersInterface || is_string($parameters)) {
            return $this->options->connections->create($parameters);
        }

        if (is_array($parameters)) {
            if (!isset($parameters[0])) {
                return $this->options->connections->create($parameters);
            }

            $options = $this->options;

            if ($options->defined('aggregate')) {
                $initializer = $this->getConnectionInitializerWrapper($options->aggregate);
                $connection = $initializer($parameters, $options);
            } elseif ($options->defined('replication')) {
                $replication = $options->replication;

                if ($replication instanceof AggregateConnectionInterface) {
                    $connection = $replication;
                    $options->connections->aggregate($connection, $parameters);
                } else {
                    $initializer = $this->getConnectionInitializerWrapper($replication);
                    $connection = $initializer($parameters, $options);
                }
            } else {
                $connection = $options->cluster;
                $options->connections->aggregate($connection, $parameters);
            }

            return $connection;
        }

        if (is_callable($parameters)) {
            $initializer = $this->getConnectionInitializerWrapper($parameters);
            $connection = $initializer($this->options);

            return $connection;
        }

        throw new \InvalidArgumentException('Invalid type for connection parameters.');
    }

    /**
     * Wraps a callable to make sure that its returned value represents a valid
     * connection type.
     *
     * @param mixed $callable
     *
     * @return \Closure
     */
    protected function getConnectionInitializerWrapper($callable)
    {
        return function () use ($callable) {
            $connection = call_user_func_array($callable, func_get_args());

            if (!$connection instanceof ConnectionInterface) {
                throw new \UnexpectedValueException(
                    'The callable connection initializer returned an invalid type.'
                );
            }

            return $connection;
        };
    }

    public function getProfile()
    {
        return $this->profile;
    }

    /**
     * Creates a new client instance for the specified connection ID or alias,
     * only when working with an aggregate connection (cluster, replication).
     * The new client instances uses the same options of the original one.
     *
     * @param string $connectionID Identifier of a connection.
     * @return Client
     * @throws NotSupportedException
     */
    public function getClientFor($connectionID)
    {
        if (!$connection = $this->getConnectionById($connectionID)) {
            throw new \InvalidArgumentException("Invalid connection ID: $connectionID.");
        }

        return new static($connection, $this->options);
    }

    /**
     * Retrieves the specified connection from the aggregate connection when the
     * client is in cluster or replication mode.
     *
     * @param string $connectionID Index or alias of the single connection.
     *
     * @throws NotSupportedException
     *
     * @return \Predis\Connection\NodeConnectionInterface
     */
    public function getConnectionById($connectionID)
    {
        if (!$this->connection instanceof AggregateConnectionInterface) {
            throw new NotSupportedException(
                'Retrieving connections by ID is supported only by aggregate connections.'
            );
        }

        return $this->connection->getConnectionById($connectionID);
    }

    /**
     * Closes the underlying connection and disconnects from the server.
     *
     * This is the same as `Client::disconnect()` as it does not actually send
     * the `QUIT` command to Redis, but simply closes the connection.
     */
    public function quit()
    {
        $this->disconnect();
    }

    /**
     * Closes the underlying connection and disconnects from the server.
     */
    public function disconnect()
    {
        $this->connection->disconnect();
    }

    /**
     * Returns the current state of the underlying connection.
     *
     * @return bool
     */
    public function isConnected()
    {
        return $this->connection->isConnected();
    }

    /**
     * Executes a command without filtering its arguments, parsing the response,
     * applying any prefix to keys or throwing exceptions on Redis errors even
     * regardless of client options.
     *
     * It is possible to identify Redis error responses from normal responses
     * using the second optional argument which is populated by reference.
     *
     * @param array $arguments Command arguments as defined by the command signature.
     * @param bool  $error     Set to TRUE when Redis returned an error response.
     *
     * @return mixed
     */
    public function executeRaw(array $arguments, &$error = null)
    {
        $error = false;

        $response = $this->connection->executeCommand(
            new RawCommand($arguments)
        );

        if ($response instanceof ResponseInterface) {
            if ($response instanceof ErrorResponseInterface) {
                $error = true;
            }

            return (string)$response;
        }

        return $response;
    }

    /**
     * Creates a new pipeline context and returns it, or returns the results of
     * a pipeline executed inside the optionally provided callable object.
     *
     * @param mixed ... Array of options, a callable for execution, or both.
     *
     * @return Pipeline|array
     */
    public function pipeline()
    {
        return $this->sharedContextFactory('createPipeline', func_get_args());
    }

    /**
     * Executes the specified initializer method on `$this` by adjusting the
     * actual invokation depending on the arity (0, 1 or 2 arguments). This is
     * simply an utility method to create Redis contexts instances since they
     * follow a common initialization path.
     *
     * @param string $initializer Method name.
     * @param array  $argv        Arguments for the method.
     *
     * @return mixed
     */
    private function sharedContextFactory($initializer, $argv = null)
    {
        switch (count($argv)) {
            case 0:
                return $this->$initializer();

            case 1:
                return is_array($argv[0])
                    ? $this->$initializer($argv[0])
                    : $this->$initializer(null, $argv[0]);

            case 2:
                list($arg0, $arg1) = $argv;

                return $this->$initializer($arg0, $arg1);

            default:
                return $this->$initializer($this, $argv);
        }
    }

    /**
     * Creates a new monitor consumer and returns it.
     *
     * @return MonitorConsumer
     */
    public function monitor()
    {
        return new MonitorConsumer($this);
    }

    public function getIterator()
    {
        $clients = [];
        $connection = $this->getConnection();

        if (!$connection instanceof \Traversable) {
            throw new ClientException('The underlying connection is not traversable');
        }

        foreach ($connection as $node) {
            $clients[(string)$node] = new static($node, $this->getOptions());
        }

        return new \ArrayIterator($clients);
    }

    public function getConnection()
    {
        return $this->connection;
    }

    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Opens the underlying connection and connects to the server.
     */
    public function connect()
    {
        $this->connection->connect();
    }

    public function __call($commandID, $arguments)
    {
        return $this->executeCommand(
            $this->createCommand($commandID, $arguments)
        );
    }

    public function executeCommand(CommandInterface $command)
    {
        $response = $this->connection->executeCommand($command);

        if ($response instanceof ResponseInterface) {
            if ($response instanceof ErrorResponseInterface) {
                $response = $this->onErrorResponse($command, $response);
            }

            return $response;
        }

        return $command->parseResponse($response);
    }

    /**
     * Handles -ERR responses returned by Redis.
     *
     * @param CommandInterface       $command  Redis command that generated the error.
     * @param ErrorResponseInterface $response Instance of the error response.
     *
     * @throws ServerException
     *
     * @return mixed
     */
    protected function onErrorResponse(CommandInterface $command, ErrorResponseInterface $response)
    {
        if ($command instanceof ScriptCommand && $response->getErrorType() === 'NOSCRIPT') {
            $eval = $this->createCommand('EVAL');
            $eval->setRawArguments($command->getEvalArguments());

            $response = $this->executeCommand($eval);

            if (!$response instanceof ResponseInterface) {
                $response = $command->parseResponse($response);
            }

            return $response;
        }

        if ($this->options->exceptions) {
            throw new ServerException($response->getMessage());
        }

        return $response;
    }

    public function createCommand($commandID, $arguments = [])
    {
        return $this->profile->createCommand($commandID, $arguments);
    }

    /**
     * Actual pipeline context initializer method.
     *
     * @param array $options  Options for the context.
     * @param mixed $callable Optional callable used to execute the context.
     *
     * @return Pipeline|array
     */
    protected function createPipeline(array $options = null, $callable = null)
    {
        if (isset($options['atomic']) && $options['atomic']) {
            $class = 'Predis\Pipeline\Atomic';
        } elseif (isset($options['fire-and-forget']) && $options['fire-and-forget']) {
            $class = 'Predis\Pipeline\FireAndForget';
        } else {
            $class = 'Predis\Pipeline\Pipeline';
        }

        /*
         * @var ClientContextInterface
         */
        $pipeline = new $class($this);

        if (isset($callable)) {
            return $pipeline->execute($callable);
        }

        return $pipeline;
    }
}
