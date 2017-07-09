<?php
/**
 * Created by PhpStorm.
 * User: crownclown67
 * Date: 08.06.17
 * Time: 20:55
 */

namespace spark\cache\service;


use Doctrine\Common\Util\ClassUtils;
use spark\core\annotation\Inject;
use spark\core\definition\BeanProxy;
use spark\utils\Objects;
use spark\utils\StringUtils;

class CacheableServiceBeanProxy implements BeanProxy {

    private $bean;
    private $className;

    /**
     * @Inject()
     * @var CacheService
     */
    private $cacheService;

    /**
     *
     * @param $bean
     */
    public function __construct($bean) {
        $this->bean = $bean;
        $this->className = Objects::getClassName($this->bean);
    }

    function __call($methodName, $arguments) {
        $cacheMethodName = $this->getCacheName($methodName);

        if ($this->cacheService->isCacheable($cacheMethodName)) {
            $cached = $this->cacheService->getCached($cacheMethodName, $arguments);

            if (Objects::isNotNull($cached)) {
                return $cached;
            }
            $result = Objects::invokeMethod($this->bean, $methodName, $arguments);
            $this->cacheService->cache($cacheMethodName, $arguments, $result);
            return $result;
        } else {
            return Objects::invokeMethod($this->bean, $methodName, $arguments);
        }
    }

    /**
     * @return mixed
     */
    public function getBean() {
        return $this->bean;
    }

    /**
     * @return CacheService
     */
    public function getCacheService() {
        return $this->cacheService;
    }

    /**
     * @param CacheService $cacheService
     */
    public function setCacheService($cacheService) {
        $this->cacheService = $cacheService;
    }

    /**
     * @param $name
     * @return string
     */
    private function getCacheName($name) {
        return $this->getClassName() . "#" . $name;
    }

    /**
     * @return string
     */
    private function getClassName() {
        return $this->className;
    }


}