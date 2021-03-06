<?php
/**
 *
 *
 * Date: 04.02.17
 * Time: 12:26
 */

namespace Spark\Core\Resource;


use Spark\Utils\Collections;

class ResourcePath {

    protected $paths;

    /**
     * Path constructor.
     */
    public function __construct($paths = array()) {
        $this->paths  = Collections::asArray($paths);
    }

    /**
     * @return array
     */
    public function getPaths() {
        return $this->paths;
    }


}