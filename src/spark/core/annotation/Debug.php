<?php
/**
 * Created by PhpStorm.
 * User: primosz67
 * Date: 09.10.16
 * Time: 20:18
 */

namespace spark\core\annotation;
use Doctrine\Common\Annotations\Annotation\Target;
use Doctrine\ORM\Mapping\Annotation;


/**
 * @Annotation
 * @Target({"CLASS"})
 */
final class Debug implements Annotation {

}