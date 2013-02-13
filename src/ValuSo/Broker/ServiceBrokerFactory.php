<?php
namespace ValuSo\Broker;

use ValuSo\Broker\ServiceBroker;
use ValuSo\Broker\ServiceLoader;
use Zend\Cache\Storage\StorageInterface;
use Zend\Mvc\Service\ServiceManagerConfig;
use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\Cache\StorageFactory;

/**
 * ServiceBroker factory
 *
 */
class ServiceBrokerFactory implements FactoryInterface
{

    /**
     * Create a ServiceBroker
     * 
     * {@see ValuSo\Broker\ServiceBroker} uses {@see Zend\ServiceManager\ServiceManager} internally to initialize service
     * instances. {@see Zend\Mvc\Service\ServiceManagerConfig} for how to configure service manager.
     * 
     * This factory uses following configuration scheme:
     * <code>
     * [
     *     'valu_so' => [
     *         'use_main_locator'   => <true>|<false>, // Set true to add main service locator as a peering service manager
     *         'factories'          => [...] // See Zend\Mvc\Service\ServiceManagerConfig
     *         'invokables'         => [...] // See Zend\Mvc\Service\ServiceManagerConfig
     *         'abstract_factories' => [...] // See Zend\Mvc\Service\ServiceManagerConfig
     *         'shared'             => [...] // See Zend\Mvc\Service\ServiceManagerConfig
     *         'aliases'            => [...] // See Zend\Mvc\Service\ServiceManagerConfig
     *         'services' => [
     *             '<id>' => [
     *                 'name'     => '<ServiceName>', // Name of the service
     *                 'options'  => [...] // [optional] Options passed to service when initialized,
     *                 'class'    => '<Class>', // [optional] Service class (same as defining it in 'invokables')
     *                 'factory'  => '<Class>', // [optional] Factory class  (same as defining it in 'factories')
     *                 'service'  => <Object|Closure>, // [optional] Service object/closure
     *                 'priority' => <Priority> // [optinal] Priority number, defaults to 1, highest number is executed first
     *             ]
     *         ]
     *     ]
     * ]
     * </code>
     * 
     * @see \Zend\ServiceManager\FactoryInterface::createService()
     * @return ServiceBroker
     */
    public function createService(ServiceLocatorInterface $serviceLocator)
    {
        $config    = $serviceLocator->get('Config');
        $config    = empty($config['valu_so']) ? [] : $config['valu_so'];
        
        $cacheConfig = isset($config['cache']) ? $config['cache'] : null;
        
        /**
         * Configure loader
         */
        $loaderOptions = array(
            'locator' => $serviceLocator->createScopedServiceManager()
        );
        
        if (!empty($config['services'])) {
            $loaderOptions['services'] = $config['services'];
        }
        unset($config['services']);
        
        if (isset($config['use_main_locator']) && $config['use_main_locator']) {
            $peeringManager = $serviceLocator;
        } else {
            $peeringManager = null;
        }
        unset($config['use_main_locator']);
        
        // Pass other configurations as service plugin manager configuration
        if (!empty($config)) {
            
            if (isset($cacheConfig['enabled']) && !$cacheConfig['enabled']) {
                unset($config['cache']);
            } elseif (!isset($cacheConfig['adapter'])) {
                $cache = $serviceLocator->get('Cache');
                
                if ($cache instanceof StorageInterface) {
                    $config['cache'] = $cache;
                }
            }
            
            $smConfig = new ServicePluginManagerConfig($config);
            $loaderOptions['service_manager'] = $smConfig;
        }
        
        // Initialize loader
        $loader = new ServiceLoader(
            $loaderOptions
        );
        
        if ($peeringManager) {
            $loader->addPeeringServiceManager($peeringManager);
        }
        
        $broker = new ServiceBroker();
        $broker->setLoader($loader);
        
        return $broker;
    }
}