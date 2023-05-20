<?php

namespace Components\Cache\Memcached;

use Components\VMConfig\Configs\LocalConfig;
use Components\VMConfig\Services\MemcachedConfig;

class MemcachedConfigWithUrlTimeout
{
    private MemcachedConfig $configMemcached;
    private $configLocal;

    public function __construct(MemcachedConfig $config, LocalConfig $localConfig)
    {
        $this->configMemcached = $config;
        $this->configLocal = $localConfig->asArray();
    }

    /**
     * @return array<string>
     */
    public function asArray(): array
    {
        if (!isset($this->configLocal['Memcached']) || !is_array($this->configLocal['Memcached'])) {
            throw new \InvalidArgumentException("Undefined Memcached section in local config");
        }
        if (
            !isset($this->configLocal['Memcached']['recv_timeout'])
            || !is_numeric($this->configLocal['Memcached']['recv_timeout'])
        ) {
            throw new \InvalidArgumentException("Wrong Memcached recv_timeout value in local config");
        }
        $config = $this->configMemcached->asArray();
        return [
            $config,
            [
                'no_block' => true,
                'recv_timeout' => $this->configLocal['Memcached']['recv_timeout'],
            ]
        ];
    }
}
