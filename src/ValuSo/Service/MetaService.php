<?php
namespace ValuSo\Service;

use ValuSo\Broker\ServiceBroker;
use Zend\ServiceManager\ServiceLocatorAwareInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use ValuSo\Annotation\AnnotationBuilder;
use ValuSo\Annotation as ValuService;
use Zend\Cache\Storage\StorageInterface as Cache;
use \ReflectionClass;
use \ReflectionMethod;

class MetaService
    implements ServiceLocatorAwareInterface
{
    const CACHE_ID_PREFIX = 'valu_so_meta_';
    
    /**
     * @var ServiceLocatorInterface
     */
    protected $serviceLocator;
    
    /**
     * Annotation builder instance
     * 
     * @var AnnotationBuilder
     */
    protected $annotationBuilder;
    
    /**
     * Service broker instance
     *
     * @var \ValuSo\Broker\ServiceBroker
     */
    protected $serviceBroker;
    
    /**
     * Cache adapter
     * 
     * @var \Zend\Cache\Storage\StorageInterface
     */
    protected $cache;
    
    /**
     * Retrieve service description
     * 
     * @param string $serviceId Service ID
     * 
     * @ValuService\Context({"cli", "http", "http-get"})
     */
    public function describe($serviceId)
    {
        $cache = $this->getCache();
        $cacheId = self::CACHE_ID_PREFIX . $serviceId;
        
        if ($cache && false && ($description = $this->getCache()->getItem($cacheId))) {
            return $description;
        }
        
        $loader = $this->getServiceLoader();
        $options = $loader->getServiceOptions($serviceId);
        
        $service = $loader->getServicePluginManager()->get($serviceId, $options, true, false);
        
        if (!$service) {
            return null;
        }
        
        $description = [];
        $description['service_id'] = $serviceId;
        
        if (!is_callable($service)) {
            $specs = $this->getAnnotationBuilder()->getServiceSpecification($service);
            $specs = $specs->getArrayCopy();
            $description = array_merge($specs, $description);
        }
        
        $reflectionClass = new ReflectionClass($service);
        
        // Convert associative 'operations' array into numeric array
        if (isset($description['operations'])) {
            $description['operations'] = $this->decorateOperations($reflectionClass, $description['operations']);
        }
        
        if ($cache) {
            $cache->setItem($cacheId, $description);
        }
        
        return $description;
    }
    
    /**
     * Retrieve description for all services matched by IDs in
     * array $services
     * 
     * @param array $services
     * @return array
     * 
     * @ValuService\Context({"cli", "http", "http-get"})
     */
    public function describeMany(array $services)
    {
        $descriptions = [];
        foreach ($services as $serviceId) {
            $descriptions[] = $this->describe($serviceId);
        }
        
        return $descriptions;
    }
    
    /**
     * Retrieve description for all services
     * 
     * @return array
     * 
     * @ValuService\Context({"cli", "http", "http-get"})
     */
    public function describeAll()
    {
        $services = $this->getServiceLoader()->getServiceIds();
        return $this->describeMany($services);
    }
    
	/** 
     * @see \Zend\ServiceManager\ServiceLocatorAwareInterface::getServiceLocator()
     */
    public function getServiceLocator()
    {
        return $this->serviceLocator;   
    }

	/**
     * @see \Zend\ServiceManager\ServiceLocatorAwareInterface::setServiceLocator()
     */
    public function setServiceLocator(ServiceLocatorInterface $serviceLocator)
    {
        $this->serviceLocator = $serviceLocator;
    }
    
    /**
     * @return \ValuSo\Annotation\AnnotationBuilder
     */
    public function getAnnotationBuilder()
    {
        if (!$this->annotationBuilder) {
            $this->setAnnotationBuilder(
                $this->getServiceLocator()->get('valu_so.annotation_builder'));    
        }
        
        return $this->annotationBuilder;
    }
    
    /**
     * Retrieve service broker instance
     *
     * @return \ValuSo\Broker\ServiceBroker
     */
    public function getServiceBroker()
    {
        if (!$this->serviceBroker) {
            $this->setServiceBroker($this->getServiceLocator()->get('ServiceBroker'));
        }
        
        return $this->serviceBroker;
    }
    
    /**
     * @see \ValuSo\Feature\ServiceBrokerAwareInterface::setServiceBroker()
     */
    public function setServiceBroker(ServiceBroker $serviceBroker)
    {
        $this->serviceBroker = $serviceBroker;
    }
    
    /**
     * @param AnnotationBuilder $annotationBuilder
     */
    public function setAnnotationBuilder(AnnotationBuilder $annotationBuilder)
    {
        $this->annotationBuilder = $annotationBuilder;
    }
    
    public function setCache(Cache $cache)
    {
        $this->cache = $cache;
    }
    
    public function getCache()
    {
        if (!$this->cache) {
            $this->setCache($this->getServiceLocator()->get('ObjectCache'));
        }
        
        return $this->cache;
    }
    
    /**
     * @return \ValuSo\Broker\ServiceLoader
     */
    protected function getServiceLoader()
    {
        return $this->getServiceBroker()->getLoader();
    }
    
    private function decorateOperations(ReflectionClass $reflectionClass, $operations)
    {
        $reflectionMethods  = $reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC);
        $methodAliases      = array();
        
        // Loop through all PUBLIC methods this time to generate invoke mapping
        foreach ($reflectionMethods as $method) {
        
            $name = $method->getName();
            
            // This operation is not available
            if (!isset($operations[$name])) {
                continue;
            }
        
            $params = [];
            foreach ($method->getParameters() as $param) {
                
                $specs['name'] = $param->getName();
                
                if ($param->isDefaultValueAvailable()) {
                    $specs['default'] = var_export($param->getDefaultValue(), true);
                    $specs['required'] = true;
                } else {
                    $specs['required'] = true; 
                }
                
                $params[] = $specs;
            }
        
            $operations[$name]['params'] = $params;
        }
        
        $operationsArray = [];
        foreach ($operations as $name => $specs) {
            
            // Skip operations beginning with two underscores
            if (strpos($name, '__') === 0) {
                continue;
            }
            
            $specs['name'] = $name;
            $operationsArray[] = $specs;
        }
        
        return $operationsArray;
    }
}