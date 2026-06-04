<?php

declare(strict_types=1);

namespace Libsql\Laravel\Database;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;

class LibsqlConnectionFactory extends ConnectionFactory
{
    public function __construct(Container $container)
    {
        parent::__construct($container);
    }

    protected function createConnection($driver, $connection, $database, $prefix = '', array $config = [])
    {
        $config['url'] = $this->resolveUrl($config);
        $config['driver'] = 'libsql';

        $connection = function () use ($config) {
            return new LibsqlDatabase($config);
        };

        return new LibsqlConnection($connection(), $database, $prefix, $config);
    }

    /**
     * Resolve the libSQL connection url from the connection config.
     *
     * Supports the modern Turso style (an explicit `url`, e.g.
     * `libsql://your-db.turso.io`) while remaining backwards compatible with
     * the legacy `host`/`port` style. When neither is provided the url is left
     * empty so the connection falls back to a local/embedded database.
     */
    protected function resolveUrl(array $config): string
    {
        if (!empty($config['url'])) {
            return $config['url'];
        }

        if (!empty($config['host'])) {
            $driver = $config['driver'] ?? 'libsql';
            $port = isset($config['port']) ? ":{$config['port']}" : '';

            return "{$driver}://{$config['host']}{$port}";
        }

        return '';
    }

    public function createConnector(array $config)
    {
        //
    }
}
