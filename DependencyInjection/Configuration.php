<?php

namespace SN\DeployBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * This is the class that validates and merges configuration from your app/config files.
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/configuration.html}
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode    = $treeBuilder->root('sn_deploy');

        // @formatter:off
        $rootNode
            ->children()
                ->scalarNode('composer')->defaultValue('composer')->end()
                ->scalarNode('default_environment')->defaultValue(null)->end()
                ->arrayNode('environments')
                    ->prototype('array')
                        ->children()
                            ->scalarNode('branch')->defaultValue(null)->end()
                            ->booleanNode('check_version')->defaultValue(true)->end()
                            ->scalarNode('ssh_host')->end()
                            ->integerNode('ssh_port')->defaultValue(22)->end()
                            ->scalarNode('ssh_user')->end()
                            ->scalarNode('remote_app_dir')->end()
                            ->arrayNode('exclude')
                                ->prototype('scalar')->end()
                                ->end()
                            ->arrayNode('cache_clear')
                                ->prototype('scalar')->end()
                                ->end()
                            ->arrayNode('pre_upload')
                                ->prototype('scalar')->end()
                                ->end()
                            ->arrayNode('post_upload')
                                ->prototype('scalar')->end()
                                ->end()
                            ->arrayNode('pre_upload_remote')
                                ->prototype('scalar')->end()
                                ->end()
                            ->arrayNode('post_upload_remote')
                                ->prototype('scalar')->end()
                                ->end()
                            ->end()
                    ->end() // end array-node environments->prototype
                ->end() // end array-node environments
            ->end(); // end children
        // @formatter:on

        // Here you should define the parameters that are allowed to
        // configure your bundle. See the documentation linked above for
        // more information on that topic.

        return $treeBuilder;
    }
}
