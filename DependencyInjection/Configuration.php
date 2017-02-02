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

        $rootNode
            ->children()
            ->scalarNode('composer')->defaultValue('composer')->end()
                ->arrayNode('environments')
                ->children()
                    ->arrayNode('prod')
                        ->children()
                            ->scalarNode('branch')->defaultValue(null)->end()
                            ->scalarNode('host')->end()
                            ->integerNode('port')->defaultValue(22)->end()
                            ->booleanNode('check_version')->defaultValue(true)->end()
                            ->scalarNode('user')->end()
                            ->scalarNode('webroot')->end()
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
                    ->end()
                    ->arrayNode('test')
                        ->children()
                            ->scalarNode('branch')->defaultValue(null)->end()
                            ->scalarNode('host')->end()
                            ->integerNode('port')->defaultValue(22)->end()
                            ->booleanNode('check_version')->defaultValue(true)->end()
                            ->scalarNode('user')->end()
                            ->scalarNode('webroot')->end()
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
                    ->end()
                    ->arrayNode('dev')
                        ->children()
                            ->scalarNode('branch')->defaultValue(null)->end()
                            ->scalarNode('host')->end()
                            ->integerNode('port')->defaultValue(22)->end()
                            ->booleanNode('check_version')->defaultValue(true)->end()
                            ->scalarNode('user')->end()
                            ->scalarNode('webroot')->end()
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
                    ->end()
                ->end()
            ->end();

        // Here you should define the parameters that are allowed to
        // configure your bundle. See the documentation linked above for
        // more information on that topic.

        return $treeBuilder;
    }
}
