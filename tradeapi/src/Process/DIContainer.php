<?php

/**
 *
 * Copyright (C) 2023 Highend Technologies LLC
 * This file is part of Zignaly OpenTradeApi.
 *
 * Zignaly OpenTradeApi is free software: you can redistribute it and/or
 * modify it under the terms of the GNU General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * Zignaly OpenTradeApi is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Zignaly OpenTradeApi.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace Zignaly\Process;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Manages the Zignaly API Dependency Injection container.
 */
class DIContainer
{
    /**
     * The container object, or NULL if not initialized yet.
     *
     * @var \Symfony\Component\DependencyInjection\Container
     */
    protected static $container;

    /**
     * Sets a new global container.
     *
     * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
     *   A new container instance.
     */
    public static function setContainer(ContainerInterface $container)
    {
        static::$container = $container;
    }

    /**
     * Unsets the global container.
     */
    public static function unsetContainer()
    {
        static::$container = null;
    }

    /**
     * Returns the active global container.
     *
     * @return \Symfony\Component\DependencyInjection\Container
     *
     * @throws \RuntimeException
     */
    public static function getContainer()
    {
        if (static::$container === null) {
            throw new \RuntimeException('Container is not initialized yet.');
        }

        return static::$container;
    }

    /**
     * Initialize the DI Container with YAML service definition.
     *
     * @throws \Exception When YAML service loader failes.
     *
     * @return \Symfony\Component\DependencyInjection\Container
     */
    public static function init() {
        $containerBuilder = new ContainerBuilder();
        $loader = new YamlFileLoader($containerBuilder, new FileLocator(__DIR__));
        $loader->load('services.yaml');
        self::setContainer($containerBuilder);

        return self::getContainer();
    }

}
