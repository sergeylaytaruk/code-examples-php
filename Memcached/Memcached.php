<?php

use Components\Cache\Memcached\MemcachedConfigWithUrlTimeout;
use Components\VMConfig\Configs\LocalConfig;
use Components\VMConfig\Services\MemcachedConfig;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\MemcachedAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

$definition[] = [
    'cache.adapter' => static function () {
        $config = (new MemcachedConfigWithUrlTimeout(
            new MemcachedConfig(
                new LocalConfig()
            ),
            new LocalConfig()
        ))->asArray();
        $memcachedAdapter = new MemcachedAdapter(
            MemcachedAdapter::createConnection(
                ...$config,
            ),
            'vm.app.'
        );
        return new TagAwareAdapter(
            new ChainAdapter(
                [
                    new ArrayAdapter(),
                    $memcachedAdapter
                ]
            )
        );
    }
];
