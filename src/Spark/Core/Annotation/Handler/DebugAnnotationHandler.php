<?php

namespace Spark\Core\Annotation\Handler;

use Spark\Config;
use Spark\Core\Annotation\Handler\AnnotationHandler;
use Spark\Core\Library\Annotations;
use Spark\Utils\Collections;
use Spark\Utils\Functions;
use Spark\Utils\Objects;
use Spark\Utils\Predicates;
use Spark\Utils\StringFunctions;
use Spark\Utils\StringPredicates;
use Spark\Utils\StringUtils;

/**
 *
 *
 * Date: 30.01.17
 * Time: 08:57
 */
class DebugAnnotationHandler extends AnnotationHandler {

    private $annotationNames;

    public function __construct() {
        $this->annotationNames = Annotations::DEBUG;
    }

    /**
     *
     * @param $annotations
     * @param $class
     * @param \ReflectionClass $classReflection
     */
    public function handleClassAnnotations($annotations = array(), $class, \ReflectionClass $classReflection) {
        $defined = $this->annotationNames;
        $annotation = Collections::builder($annotations)
            ->filter(Predicates::compute($this->getClassName(), StringPredicates::equals($defined)))
            ->findFirst();

        if ($annotation->isPresent()) {
            $this->getConfig()->set(Config::DEV_ENABLED, true);
        }
    }

    /**
     * @return \Closure
     */
    private function getClassName() {
        return function ($x) {
            return Objects::getClassName($x);
        };
    }

}