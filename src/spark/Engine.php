<?php

namespace spark;

use Doctrine\Common\Annotations\AnnotationRegistry;
use ErrorException;
use ReflectionClass;
use spark\cache\ApcuBeanCache;
use spark\cache\BeanCache;
use spark\cache\TestCache;
use spark\core\annotation\handler\EnableApcuAnnotationHandler;
use spark\core\CoreConfig;
use spark\core\engine\EngineConfig;
use spark\core\engine\EngineFactory;
use spark\core\error\EngineExceptionWrapper;
use spark\core\error\ExceptionResolver;
use spark\core\error\GlobalErrorHandler;
use spark\core\interceptor\HandlerInterceptor;
use spark\core\lang\LangResourcePath;
use spark\core\library\BeanLoader;
use spark\core\processor\InitAnnotationProcessors;
use spark\core\provider\BeanProvider;
use spark\filter\FilterChain;
use spark\http\HttpCode;
use spark\http\Request;
use spark\http\RequestProvider;
use spark\http\ResponseHelper;
use spark\loader\ClassLoaderRegister;
use spark\routing\RoutingInfo;
use spark\core\lang\LangMessageResource;
use spark\http\utils\RequestUtils;
use spark\utils\BooleanUtils;
use spark\utils\ConfigHelper;
use spark\utils\FileUtils;
use spark\utils\Predicates;
use spark\utils\ReflectionUtils;
use spark\utils\UrlUtils;
use spark\utils\Asserts;
use spark\utils\Collections;
use spark\utils\Objects;
use spark\utils\StringUtils;
use spark\view\json\JsonViewHandler;
use spark\view\plain\PlainViewHandler;
use spark\view\smarty\SmartyPlugins;
use spark\view\smarty\SmartyViewHandler;
use spark\view\ViewHandlerProvider;
use spark\view\ViewModel;

/**
 * Description of Engine
 *
 * @author primosz67
 */
class Engine {
    private static $ROOT_APP_PATH;
    private $apcuExtensionLoaded;
    const CONTAINER_CACHE_KEY = "container";
    const ROUTE_CACHE_KEY = "route";
    const CONFIG_CACHE_KEY = "config";
    const INTERCEPTORS_CACHE_KEY = "interceptors";
    const ERROR_HANDLERS_CACHE_KEY = "exceptionResolvers";

    /**
     * @var EngineConfig
     */
    private $engineConfig;

    /**
     * @var Routing
     */
    private $route;

    /**
     * App configuration
     * @var Config
     */
    private $config;

    /**
     * @var Container
     */
    private $container;

    /**
     * @var BeanCache
     */
    private $beanCache;


    private $interceptors;
    private $exceptionResolvers;

    public function __construct($name, $rootAppPath) {
        $this->apcuExtensionLoaded = extension_loaded("apcu");

//        $fileList = FileUtils::getDirList($rootAppPath . "/src", array("proxy"));
//
//        $namespaces = Collections::builder($fileList)
//            ->map(StringUtils::mapReplace("/","\\"))
//            ->get();

//        $this->engineConfig = new EngineConfig($rootAppPath, $namespaces);
        $this->engineConfig = new EngineConfig($rootAppPath, array());

        self::$ROOT_APP_PATH = $rootAppPath;

        ClassLoaderRegister::register($this->engineConfig);

        $this->beanCache = new ApcuBeanCache();
        $this->beanCache->init();

        if ($this->hasAllreadyCachedData()) {
            $this->container = $this->beanCache->get($this->getCacheKey(self::CONTAINER_CACHE_KEY));
            $this->route = $this->beanCache->get($this->getCacheKey(self::ROUTE_CACHE_KEY));
            $this->config = $this->beanCache->get($this->getCacheKey(self::CONFIG_CACHE_KEY));
            $this->interceptors = $this->beanCache->get($this->getCacheKey(self::INTERCEPTORS_CACHE_KEY));
            $this->exceptionResolvers = $this->beanCache->get($this->getCacheKey(self::ERROR_HANDLERS_CACHE_KEY));
        }

        if (!$this->hasAllreadyCachedData() || isset($_GET["reset"])) {
            if ($this->apcuExtensionLoaded) {
                $this->beanCache->clearAll();
            }

            $src = $rootAppPath . "/src";

            $this->container = new Container();
            $this->route = new Routing(array());
            $this->config = new Config();

            $this->config->set("app.path", $rootAppPath);
            $this->config->set("src.path", $rootAppPath."/src");


            $initAnnotationProcessors = new InitAnnotationProcessors($this->route, $this->config, $this->container);

            $beanLoader = new BeanLoader($initAnnotationProcessors, $this->config);
            $beanLoader->addFromPath($src, array("proxy"));
            $beanLoader->addLib("spark\\core\\CoreConfig");
            $beanLoader->addPersistanceLib();
            $beanLoader->addSecurity();
            $beanLoader->process();

            $this->addBaseServices();

            $this->container->registerObj($this->container);
            $this->container->setConfig($this->config);
            $this->container->initServices();
            $this->afterAllBean();
            $beanLoader->postProcess();

            $this->interceptors = $this->container->getByType(HandlerInterceptor::CLASS_NAME);
            $this->exceptionResolvers = $this->container->getByType(ExceptionResolver::CLASS_NAME);

            if ($this->isApcuCacheEnabled()) {
                $this->beanCache->put($this->getCacheKey(self::CONFIG_CACHE_KEY), $this->config);
                $this->beanCache->put($this->getCacheKey(self::CONTAINER_CACHE_KEY), $this->container);
                $this->beanCache->put($this->getCacheKey(self::ROUTE_CACHE_KEY), $this->route);
                $this->beanCache->put($this->getCacheKey(self::INTERCEPTORS_CACHE_KEY), $this->interceptors);
                $this->beanCache->put($this->getCacheKey(self::ERROR_HANDLERS_CACHE_KEY), $this->exceptionResolvers);
            }
        }

        $engine = $this;
        /** @var GlobalErrorHandler $globalErrorHandler */
        $globalErrorHandler = $this->container->get(GlobalErrorHandler::NAME);
        $globalErrorHandler->setup($this->exceptionResolvers);
    }

    /**
     * use $this->config->getProperty("app.path")
     *
     * @deprecated
     * @return mixed
     */
    public static function getRootPath() {
        return self::$ROOT_APP_PATH;
    }

    public function run() {
        $this->runController();
    }

    private function runController() {
        $registeredHostPath = $this->getRegisteredHostPath();
        $urlName = UrlUtils::getPathInfo($registeredHostPath);

        $this->handleRequest($urlName);
    }

    /**
     * @return mixed
     */
    private function getRegisteredHostPath() {
        return UrlUtils::getHost();
    }

    private function handleRequest($urlName, $responseParams = array()) {
        $this->devToolsInit();

        $registeredHostPath = $this->getRegisteredHostPath();
        $request = $this->route->createRequest($urlName, $registeredHostPath);

        $this->updateRequest($request);

        //Interceptor
        $this->preHandleInterceptor($request);
        $this->filter($this->container->getFilters(), $request);

        //Controller
        $controllerName = $request->getControllerClassName();
        /** @var $controller Controller */


        $controller = $this->container->get($controllerName);
//        $controller = new $controllerName();
//        $controller->setContainer($this->container);

        $controller->init($request, $responseParams);

        //ACTION->VIEW
        $this->handleAction($request, $controller);
    }

    private function devToolsInit() {
        $enabled = $this->config->getProperty(Config::DEV_ENABLED);
        if ($enabled) {
            RequestUtils::setCookie("XDEBUG_SESSION", true);
        }
    }

    private function addBaseServices() {
        $this->container->register(LangMessageResource::NAME, new LangMessageResource(array()));
        $this->container->register(SmartyPlugins::NAME, new SmartyPlugins());
        $this->container->register(RequestProvider::NAME, new RequestProvider());
        $this->container->register(RoutingInfo::NAME, new RoutingInfo($this->route));
        $this->container->registerObj(new BeanProvider($this->container));
        $this->container->registerObj($this->config);
        $this->container->registerObj($this->route);
        $this->container->registerObj(new GlobalErrorHandler($this));

        $this->addViewHandlersToService();
    }

    private function afterAllBean() {
        $resourcePaths = $this->container->getByType(LangResourcePath::CLASS_NAME);

        /** @var LangMessageResource $resource */
        $resource = $this->container->get(LangMessageResource::NAME);
        $resource->addResources($resourcePaths);
    }

    private function addViewHandlersToService() {
        $smartyViewHandler = new SmartyViewHandler($this->engineConfig->getRootAppPath());
        $plainViewHandler = new PlainViewHandler();
        $jsonViewHandler = new JsonViewHandler();

        $provider = new ViewHandlerProvider();
        $this->container->register(ViewHandlerProvider::NAME, $provider);
        $this->container->register("defaultViewHandler", $smartyViewHandler);
        $this->container->register(SmartyViewHandler::NAME, $smartyViewHandler);
        $this->container->register(PlainViewHandler::NAME, $plainViewHandler);
        $this->container->register(JsonViewHandler::NAME, $jsonViewHandler);
    }

    /**
     * @param Request $request
     * @param $controller
     * @throws ErrorException
     */
    private function handleAction(Request $request, $controller) {

        /** @var $viewModel ViewModel */
        $methodName = $request->getMethodName();
        $viewModel = $controller->$methodName();

        $this->handleViewModel($request, $viewModel);
    }

    /**
     * @param ViewModel $viewModel
     * @param Request $request
     */
    private function handleView($viewModel, $request) {
        $handler = $this->container->get(ViewHandlerProvider::NAME);
        /** @var $handler ViewHandlerProvider */
        $handler->handleView($viewModel, $request);
    }


    private function filter($filters = array(), Request $request) {
        if (Collections::isNotEmpty($filters)) {
            $filtersIterator = new \ArrayIterator($filters);
            $chain = new FilterChain($filtersIterator->current(), $filtersIterator);
            $chain->doFilter($request);
        }
    }

    private function hasAllreadyCachedData() {
        return $this->apcuExtensionLoaded && $this->beanCache->has($this->getCacheKey(self::CONTAINER_CACHE_KEY));
    }

    /**
     * @return string
     */
    private function getCacheKey($key) {
        return "spark_" . $key;
    }

    /**
     * @return null
     */
    private function isApcuCacheEnabled() {
        return $this->apcuExtensionLoaded && $this->config->getProperty(EnableApcuAnnotationHandler::APCU_CACHE_ENABLED, false);
    }

    private function preHandleInterceptor(Request $request) {
        /** @var HandlerInterceptor $interceptor */
        foreach ($this->interceptors as $interceptor) {
            if (BooleanUtils::isFalse($interceptor->preHandle($request))) {
                break;
            }
        }
    }

    private function postHandleIntercetors(Request $request,ViewModel $viewModel) {
        /** @var HandlerInterceptor $interceptor */
        foreach ($this->interceptors as $interceptor) {
            $interceptor->postHandle($request, $viewModel);
        }

    }

    /**
     *
     * @param Request $request
     * @param $viewModel
     * @throws ErrorException
     * @throws common\IllegalStateException
     */
    public  function handleViewModel(Request $request, $viewModel) {
        Asserts::checkState($viewModel instanceof ViewModel, "Wrong viewModel type. Returned type from controller needs to be instance of ViewModel.");

        $this->postHandleIntercetors($request, $viewModel);

        if (isset($viewModel)) {
            $redirect = $viewModel->getRedirect();
            if (StringUtils::isNotBlank($redirect)) {
                $request->instantRedirect($redirect);
            }

            $page = UrlUtils::getSite();

            //Deprecated use e.g.: {path path="/next/page"}
            $viewModel->add("web", array(
                "page" => $page
            ));

            $this->handleView($viewModel, $request);
        } else {
            throw new ErrorException("ViewModel not found. Did you initiated ViewModel? ");
        }
    }

    /**
     *
     * @param $request
     * @throws \Exception
     */
    public function updateRequest($request) {
        /** @var RequestProvider $requestProvider */
        $requestProvider = $this->container->get(RequestProvider::NAME);
        $requestProvider->setRequest($request);
    }

}
