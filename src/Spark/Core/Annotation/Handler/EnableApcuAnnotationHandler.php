<?php

namespace Spark\Core\Annotation\Handler;

use Spark\Config;
use Spark\Core\Annotation\EnableApcuBeanCache;
use Spark\Core\Annotation\Handler\AnnotationHandler;
use Spark\Utils\Collections;
use Spark\Utils\Functions;
use Spark\Utils\Objects;
use Spark\Utils\Predicates;
use Spark\Utils\StringUtils;

/**
 * Created by PhpStorm.
 * User: primosz67
 * Date: 30.01.17
 * Time: 08:57
 */
class EnableApcuAnnotationHandler extends AnnotationHandler {

    const APCU_CACHE_ENABLED = "Spark.apcu.cache.enabled";
    const APCU_CACHE_PREFIX = "Spark.apcu.cache.prefix";
    const APCU_CACHE_RESET = "Spark.apcu.cache.reset";

    private $annotationName;

    public function __construct() {
        $this->annotationName = "Spark\\core\\annotation\\EnableApcuBeanCache";
    }

    public function handleClassAnnotations($annotations = array(), $class, \ReflectionClass $classReflection) {

        $annotation = Collections::builder($annotations)
            ->findFirst(Predicates::compute($this->getClassName(), Predicates::equals($this->annotationName)));

        if ($annotation->isPresent()) {
            /** @var EnableApcuBeanCache $param */
            $param = $annotation->getOrNull();

            $config = $this->getConfig();
            $config->set(self::APCU_CACHE_ENABLED, true);
            $config->set(self::APCU_CACHE_PREFIX, $param->prefix);
            $config->set(self::APCU_CACHE_RESET, $param->resetParam);
        }
    }

    private function getClassName() {
        return Functions::getClassName();
    }

}