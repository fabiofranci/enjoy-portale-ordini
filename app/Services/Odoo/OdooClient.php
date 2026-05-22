<?php

declare(strict_types=1);

namespace App\Services\Odoo;

use App\Services\Odoo\Exceptions\OdooAuthenticationException;
use App\Services\Odoo\Exceptions\OdooConnectionException;
use App\Services\Odoo\Exceptions\OdooException;
use Psr\Log\LoggerInterface;
use Ripcord\Client\Client;
use Ripcord\Exceptions\RemoteException;
use Ripcord\Exceptions\TransportException;
use Ripcord\Ripcord;
use Throwable;

final class OdooClient
{
    private const DEFAULT_TIMEOUT = 15;
    private const HTTP_USER_AGENT = 'Laravel/OdooClient';

    private string $url;
    private string $database;
    private string $username;
    private string $password;
    private ?string $apiKey;
    private int $timeout;
    private ?int $uid = null;
    private ?Client $commonClient = null;
    private ?Client $objectClient = null;
    private LoggerInterface $logger;

    public function __construct(array $config, ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? app(LoggerInterface::class);

        $this->url = rtrim(trim((string) ($config['url'] ?? '')), '/');
        $this->database = trim((string) ($config['database'] ?? ''));
        $this->username = trim((string) ($config['username'] ?? ''));
        $this->password = trim((string) ($config['password'] ?? ''));
        $this->apiKey = isset($config['api_key']) ? trim((string) $config['api_key']) : null;
        $this->timeout = isset($config['timeout']) ? (int) $config['timeout'] : self::DEFAULT_TIMEOUT;

        $this->validateConfig();
    }

    public static function fromConfig(?array $config = null, ?LoggerInterface $logger = null): self
    {
        return new self($config ?? config('services.odoo', []), $logger);
    }

    public function authenticate(): int
    {
        if ($this->uid !== null) {
            return $this->uid;
        }

        $this->logger->debug('Odoo authentication started', [
            'url' => $this->commonUrl(),
            'database' => $this->database,
            'username' => $this->username,
            'auth_method' => $this->authMethod(),
        ]);

        try {
            $result = $this->commonClient()->authenticate(
                $this->database,
                $this->username,
                $this->authSecret(),
                []
            );

            $this->logger->debug('Odoo authentication response', [
                'uid' => $result,
            ]);

            if (!is_int($result) || $result <= 0) {
                throw new OdooAuthenticationException('Odoo authentication failed: invalid user id returned.');
            }

            $this->uid = $result;

            return $this->uid;
        } catch (TransportException $exception) {
            $this->logger->error('Odoo authentication transport error', [
                'exception' => $exception,
                'url' => $this->commonUrl(),
            ]);

            throw new OdooConnectionException('Odoo authentication failed: transport error.', 0, $exception);
        } catch (RemoteException $exception) {
            $this->logger->error('Odoo authentication remote error', [
                'exception' => $exception,
                'url' => $this->commonUrl(),
            ]);

            throw new OdooAuthenticationException('Odoo authentication failed: invalid credentials or server error.', 0, $exception);
        } catch (Throwable $exception) {
            $this->logger->error('Odoo authentication unexpected error', [
                'exception' => $exception,
                'url' => $this->commonUrl(),
            ]);

            throw new OdooException('Odoo authentication failed.', 0, $exception);
        }
    }

    public function searchRead(string $model, array $domain = [], array $fields = [], array $options = []): array
    {
        $queryOptions = ['fields' => $fields];

        if (isset($options['limit'])) {
            $queryOptions['limit'] = (int) $options['limit'];
        }
        if (isset($options['offset'])) {
            $queryOptions['offset'] = (int) $options['offset'];
        }
        if (isset($options['order'])) {
            $queryOptions['order'] = (string) $options['order'];
        }
        if (isset($options['context'])) {
            $queryOptions['context'] = $options['context'];
        }

        $this->logger->debug('Odoo searchRead request', [
            'model' => $model,
            'domain' => $domain,
            'fields' => $fields,
            'options' => $queryOptions,
        ]);

        $result = $this->executeKw($model, 'search_read', [$domain], $queryOptions);

        if (!is_array($result)) {
            throw new OdooException(sprintf('Odoo searchRead expected array response, got %s.', get_debug_type($result)));
        }

        return $result;
    }

    public function executeKw(string $model, string $method, array $args = [], array $kwargs = []): mixed
    {
        $uid = $this->authenticate();

        $this->logger->debug('Odoo execute_kw request', [
            'model' => $model,
            'method' => $method,
            'args' => $args,
            'kwargs' => $kwargs,
            'url' => $this->objectUrl(),
            'uid' => $uid,
        ]);

        try {
            $result = $this->objectClient()->execute_kw(
                $this->database,
                $uid,
                $this->authSecret(),
                $model,
                $method,
                $args,
                $kwargs
            );

            $this->logger->debug('Odoo execute_kw response', [
                'response_type' => get_debug_type($result),
            ]);

            return $result;
        } catch (TransportException $exception) {
            $this->logger->error('Odoo request transport error', [
                'exception' => $exception,
                'url' => $this->objectUrl(),
                'model' => $model,
                'method' => $method,
            ]);

            throw new OdooConnectionException('Odoo request failed because the transport layer could not reach the server.', 0, $exception);
        } catch (RemoteException $exception) {
            $this->logger->error('Odoo request remote error', [
                'exception' => $exception,
                'url' => $this->objectUrl(),
                'model' => $model,
                'method' => $method,
            ]);

            throw new OdooException('Odoo request failed: server returned a fault.', 0, $exception);
        } catch (Throwable $exception) {
            $this->logger->error('Odoo request unexpected error', [
                'exception' => $exception,
                'url' => $this->objectUrl(),
                'model' => $model,
                'method' => $method,
            ]);

            throw new OdooException('Odoo request failed with an unexpected error.', 0, $exception);
        }
    }

    private function commonClient(): Client
    {
        if ($this->commonClient === null) {
            $this->commonClient = $this->buildClient($this->commonUrl());
            $this->commonClient->_throwExceptions = true;
        }

        return $this->commonClient;
    }

    private function objectClient(): Client
    {
        if ($this->objectClient === null) {
            $this->objectClient = $this->buildClient($this->objectUrl());
            $this->objectClient->_throwExceptions = true;
        }

        return $this->objectClient;
    }

    private function buildClient(string $endpoint): Client
    {
        return Ripcord::client($endpoint);
    }

    private function commonUrl(): string
    {
        return sprintf('%s/xmlrpc/2/common', $this->url);
    }

    private function objectUrl(): string
    {
        return sprintf('%s/xmlrpc/2/object', $this->url);
    }

    private function validateConfig(): void
    {
        if ($this->url === '' || $this->database === '' || $this->username === '' || ($this->password === '' && $this->apiKey === null)) {
            throw new OdooException('Missing required Odoo configuration: url, database, username and either password or api_key are required.');
        }
    }

    private function authSecret(): string
    {
        return $this->apiKey ?? $this->password;
    }

    private function authMethod(): string
    {
        return $this->apiKey !== null ? 'api_key' : 'password';
    }
}
