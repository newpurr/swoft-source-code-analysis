<?php declare(strict_types=1);

namespace Swoft\Bean;


use function array_keys;
use function class_exists;
use function count;
use function end;
use function is_array;
use function is_string;
use function method_exists;
use ReflectionClass;
use ReflectionException;
use function sprintf;
use function strpos;
use Swoft\Annotation\Exception\AnnotationException;
use Swoft\Bean\Annotation\Mapping\Bean;
use Swoft\Bean\Contract\ContainerInterface;
use Swoft\Bean\Contract\HandlerInterface;
use Swoft\Bean\Definition\ArgsInjection;
use Swoft\Bean\Definition\MethodInjection;
use Swoft\Bean\Definition\ObjectDefinition;
use Swoft\Bean\Definition\Parser\AnnotationObjParser;
use Swoft\Bean\Definition\Parser\DefinitionObjParser;
use Swoft\Bean\Definition\PropertyInjection;
use Swoft\Bean\Exception\ContainerException;
use Swoft\Stdlib\Helper\ArrayHelper;
use Swoft\Stdlib\Helper\ObjectHelper;
use Swoft\Stdlib\Reflections;
use function ucfirst;

/**
 * Class Container
 */
class Container implements ContainerInterface
{
    /**
     * Init method after create bean
     */
    public const INIT_METHOD = 'init';

    /**
     * Destroy method before destroy bean
     */
    public const DESTROY_METHOD = 'destroy';

    /**
     * Default pool size
     */
    public const DEFAULT_POOL_SIZE = 100;

    /**
     * @var Container
     */
    public static $instance;

    /**
     * All load annotations
     *
     * @var array
     *
     * @example
     * [
     *    'loadNamespace' => [
     *        'className' => [
     *             'annotation' => [
     *                  new ClassAnnotation(),
     *                  new ClassAnnotation(),
     *                  new ClassAnnotation(),
     *             ]
     *             'reflection' => new ReflectionClass(),
     *             'properties' => [
     *                  'propertyName' => [
     *                      'annotation' => [
     *                          new PropertyAnnotation(),
     *                          new PropertyAnnotation(),
     *                          new PropertyAnnotation(),
     *                      ]
     *                     'reflection' => new ReflectionProperty(),
     *                  ]
     *             ],
     *            'methods' => [
     *                  'methodName' => [
     *                      'annotation' => [
     *                          new MethodAnnotation(),
     *                          new MethodAnnotation(),
     *                          new MethodAnnotation(),
     *                      ]
     *                     'reflection' => new ReflectionFunctionAbstract(),
     *                  ]
     *            ],
     *           'pathName' => '/xxx/xx/xx.php'
     *        ]
     *    ]
     * ]
     */
    private $annotations = [];

    /**
     * Annotation parser
     *
     * @var array
     *
     * @example
     * [
     *    'annotationClassName' => 'annotationParserClassName',
     * ]
     */
    private $parsers = [];

    /**
     * All definitions
     *
     * @var array
     *
     * @example
     * [
     *     'name' => [
     *         'class' => 'className',
     *         [
     *             'construnctArg',
     *             '${ref.name}', // config params
     *             '${beanName}', // object
     *         ],
     *         'propertyValue',
     *         '${ref.name}',
     *         '${beanName}',
     *         '__option' => [
     *              'scope' => '...',
     *              'alias' => '...',
     *         ]
     *     ]
     * ]
     */
    private $definitions = [];

    /**
     * All alias
     *
     * @var array
     *
     * @example
     * [
     *     'alias' => 'beanName',
     *     'alias' => 'beanName',
     *     'alias' => 'beanName'
     * ]
     */
    private $aliases = [];

    /**
     * Class all bean names (many instances)
     *
     * @var array
     *
     * @example
     * [
     *     'className' => [
     *         'beanName',
     *         'beanName',
     *         'beanName',
     *     ]
     * ]
     */
    private $classNames = [];

    /**
     * Bean definitions
     *
     * @var ObjectDefinition[]
     *
     * @example
     * [
     *     'beanName' => new ObjectDefinition,
     *     'beanName' => new ObjectDefinition,
     *     'beanName' => new ObjectDefinition
     * ]
     */
    private $objectDefinitions = [];

    /**
     * Request bean definitions
     *
     * @var ObjectDefinition[]
     *
     * @example
     * [
     *     'beanName' => new ObjectDefinition,
     *     'beanName' => new ObjectDefinition,
     *     'beanName' => new ObjectDefinition
     * ]
     */
    private $requestDefinitions = [];

    /**
     * Session bean definitions
     *
     * @var ObjectDefinition[]
     *
     * @example
     * [
     *     'beanName' => new ObjectDefinition,
     *     'beanName' => new ObjectDefinition,
     *     'beanName' => new ObjectDefinition
     * ]
     */
    private $sessionDefinitions = [];

    /**
     * Singleton pool
     *
     * @var array
     *
     * @example
     * [
     *     'beanName' => object,
     *     'beanName' => object,
     *     'beanName' => object,
     * ]
     */
    private $singletonPool = [];

    /**
     * Prototype pool
     *
     * @var array
     *
     * @example
     * [
     *     'beanName' => object,
     *     'beanName' => object,
     *     'beanName' => object,
     * ]
     */
    private $prototypePool = [];

    /**
     * Request pool
     *
     * @var array
     *
     * @example
     * [
     *     'beanName' => object,
     *     'beanName' => object,
     *     'beanName' => object,
     * ]
     */
    private $requestPool = [];

    /**
     * Session pool
     *
     * @var array
     *
     * @example
     * [
     *     'beanName' => object,
     *     'beanName' => object,
     *     'beanName' => object,
     * ]
     */
    private $sessionPool = [];

    /**
     * Bean handler
     *
     * @var HandlerInterface
     */
    private $handler;

    /**
     * Container constructor.
     */
    private function __construct()
    {
    }

    /**
     * @return Container
     */
    public static function getInstance(): Container
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Init
     *
     * @throws ContainerException
     * @throws ReflectionException
     * @throws AnnotationException
     */
    public function init(): void
    {
        // Parse annotations
        // 解析注解，并收集属性和方法的bean依赖信息
        $this->parseAnnotations();

        // Parse definitions
        // 解析bean依赖,主要是生成$this->ObjectDefinitions关系( bean => ObjectDefinition )
        $this->parseDefinitions();

        // Init beans
        // 初始化bean
        $this->initializeBeans();
    }

    public function initializeRequest(int $rid): void
    {
        // /* @var ObjectDefinition $objectDefinition */
        // foreach ($this->requestDefinitions as $beanName => $objectDefinition) {
        // TODO ...
        // }
    }

    /**
     * Get request bean
     *
     * @param string $name
     * @param string $id Usually is coroutine ID
     *
     * @return object
     * @throws ContainerException
     * @throws ReflectionException
     */
    public function getRequest(string $name, string $id)
    {
        if (isset($this->requestPool[$id][$name])) {
            return $this->requestPool[$id][$name];
        }

        if (isset($this->aliases[$name])) {
            return $this->getRequest($this->aliases[$name], $id);
        }

        // Class name
        $classNames = $this->classNames[$name] ?? [];
        if ($classNames) {
            $name = end($classNames);
            return $this->getRequest($name, $id);
        }

        if (!isset($this->requestDefinitions[$name])) {
            throw new ContainerException(sprintf('Request bean(%s) is not defined', $name));
        }

        return $this->newBean($name, $id);
    }

    /**
     * Get session bean
     *
     * @param string $name
     * @param string $sid
     *
     * @return object
     * @throws ContainerException
     * @throws ReflectionException
     */
    public function getSession(string $name, string $sid)
    {
        if (isset($this->sessionPool[$sid][$name])) {
            return $this->sessionPool[$sid][$name];
        }

        if (isset($this->aliases[$name])) {
            return $this->getSession($this->aliases[$name], $sid);
        }

        // Class name
        $classNames = $this->classNames[$name] ?? [];
        if ($classNames) {
            $name = end($classNames);
            return $this->getSession($name, $sid);
        }

        if (!isset($this->sessionDefinitions[$name])) {
            throw new ContainerException(sprintf('Session bean(%s) is not defined', $name));
        }

        return $this->newBean($name, $sid);
    }

    /**
     * Finds an entry of the container by its identifier and returns it.
     *
     * @param string $id Bean name Or alias Or class name
     *
     * When class name will return all of instance for class name
     *
     * @return object
     * @throws ContainerException
     * @throws ReflectionException
     */
    public function get($id)
    {
        // It is singleton
        if (isset($this->singletonPool[$id])) {
            return $this->singletonPool[$id];
        }

        // Prototype by clone
        if (isset($this->prototypePool[$id])) {
            return clone $this->prototypePool[$id];
        }

        // Alias name
        $aliasId = $this->aliases[$id] ?? '';
        if ($aliasId) {
            return $this->get($aliasId);
        }

        // Class name
        $classNames = $this->classNames[$id] ?? [];
        if ($classNames) {
            $id = end($classNames);
            return $this->get($id);
        }

        // Not defined
        if (!isset($this->objectDefinitions[$id])) {
            throw new ContainerException(sprintf('The bean of %s is not defined', $id));
        }

        /* @var ObjectDefinition $objectDefinition */
        $objectDefinition = $this->objectDefinitions[$id];

        // Prototype
        return $this->newBean($objectDefinition->getName());
    }

    /**
     * Many instance of one class
     *
     * @param string $className
     *
     * @return array
     * @throws ContainerException
     * @throws ReflectionException
     */
    public function gets(string $className): array
    {
        $instanceNames = $this->classNames[$className] ?? [];

        if (empty($instanceNames)) {
            return [];
        }

        $instances = [];
        foreach ($instanceNames as $instanceName) {
            $instances[] = $this->get($instanceName);
        }

        return $instances;
    }

    /**
     * Quick get exist singleton
     *
     * @param string $name
     *
     * @return object|mixed
     * @throws ContainerException
     */
    public function getSingleton(string $name)
    {
        if (isset($this->singletonPool[$name])) {
            return $this->singletonPool[$name];
        }

        if (isset($this->aliases[$name])) {
            $name = $this->aliases[$name];
            return $this->singletonPool[$name];
        }

        $classNames = $this->classNames[$name] ?? [];
        if ($classNames) {
            $name = end($classNames);
            return $this->singletonPool[$name];
        }

        throw new ContainerException(sprintf('The singleton bean "%s" is not defined', $name));
    }

    /**
     * Create object by definition
     *
     * @param string $name
     * @param array  $definition
     *
     * @return object
     * @throws ContainerException
     * @throws ReflectionException
     * @example
     *         [
     *         'class' =>  'className',
     *         [
     *         'arg',
     *         '${bean}',
     *         '${config.xxx.xxx}'
     *         ], // Index = 0 is constructor
     *
     *     'propertyName' => 'propertyValue',
     *     'propertyName' => '${bean}',
     *     'propertyName' => '${xxx.xxx.xxx}',
     *     '__option' => [
     *         'scope' => Bean::xxx,
     *         'alias' => 'aliasName'
     *     ]
     * ]
     *
     */
    public function create(string $name, array $definition = [])
    {
        if ($this->has($name)) {
            throw new ContainerException('Create ' . $name . ' bean by definition is exist!');
        }

        //  Create bean only by class name
        if (empty($definition) && class_exists($name)) {
            $definition = [
                'class' => $name
            ];
        }

        $definitionObjParser = new DefinitionObjParser($definition, [], [], $this->aliases);
        [, $objectDefinitions] = $definitionObjParser->parseDefinitions();

        $this->objectDefinitions[$name] = $objectDefinitions[$name];

        return $this->newBean($name);
    }

    /**
     * Returns true if the container can return an entry for the given identifier.
     * Returns false otherwise.
     *
     * @param string $id Bean name Or alias Or class name
     *
     * @return bool
     */
    public function has($id): bool
    {
        if (isset($this->singletonPool[$id])) {
            return true;
        }

        if (isset($this->aliases[$id])) {
            return true;
        }

        if (isset($this->classNames[$id])) {
            return true;
        }

        if (isset($this->objectDefinitions[$id])) {
            return true;
        }

        return false;
    }

    /**
     * Quick check has singleton
     *
     * @param string $name Bean name Or alias
     *
     * @return bool
     */
    public function isSingleton(string $name): bool
    {
        if (isset($this->aliases[$name])) {
            $name = $this->aliases[$name];
        }

        return isset($this->singletonPool[$name]);
    }

    /**
     * Destroy request bean
     *
     * @param string $id
     */
    public function destroyRequest(string $id): void
    {
        unset($this->requestPool[$id]);
    }

    /**
     * Destroy session bean
     *
     * @param string $sid
     */
    public function destroySession(string $sid): void
    {
        unset($this->sessionPool[$sid]);
    }

    /**
     * Add definitions
     *
     * @param array $definitions
     *
     * @return void
     */
    public function addDefinitions(array $definitions): void
    {
        $this->definitions = ArrayHelper::merge($this->definitions, $definitions);
    }

    /**
     * Add annotations
     *
     * @param array $annotations
     *
     * @return void
     */
    public function addAnnotations(array $annotations): void
    {
        $this->annotations = ArrayHelper::merge($this->annotations, $annotations);
    }

    /**
     * Add annotation parsers
     *
     * @param array $annotationParsers
     *
     * @return void
     */
    public function addParsers(array $annotationParsers): void
    {
        $this->parsers = ArrayHelper::merge($this->parsers, $annotationParsers);
    }

    /**
     * Parse annotations
     *
     * @throws ContainerException
     * @throws AnnotationException
     */
    private function parseAnnotations(): void
    {
        // 解析注解
        $annotationParser = new AnnotationObjParser(
            $this->definitions, $this->objectDefinitions, $this->classNames, $this->aliases
        );
        $annotationData   = $annotationParser->parseAnnotations($this->annotations, $this->parsers);

        [$this->definitions, $this->objectDefinitions, $this->classNames, $this->aliases] = $annotationData;
    }

    /**
     * Parse definitions
     *
     * @throws ContainerException
     */
    private function parseDefinitions(): void
    {
        // 依赖对象解析器
        $annotationParser = new DefinitionObjParser(
            $this->definitions, $this->objectDefinitions, $this->classNames, $this->aliases
        );

        // Collect info
        // 收集依赖信息
        $definitionData = $annotationParser->parseDefinitions();
        [$this->definitions, $this->objectDefinitions, $this->classNames, $this->aliases] = $definitionData;
    }

    /**
     * @param HandlerInterface $handler
     */
    public function setHandler(HandlerInterface $handler): void
    {
        $this->handler = $handler;
    }

    /**
     * @return array
     */
    public function getStats(): array
    {
        return [
            'singleton'  => count($this->singletonPool),
            'prototype'  => count($this->prototypePool),
            'definition' => count($this->definitions),
            // 'definition' => \count($this->r),
        ];
    }

    /**
     * Get bean names
     *
     * @return array
     */
    public function getNames(): array
    {
        return [
            'singleton'  => array_keys($this->singletonPool),
            'prototype'  => array_keys($this->prototypePool),
            'request'    => array_keys($this->requestPool),
            'session'    => array_keys($this->sessionPool),
            'definition' => array_keys($this->definitions),
            // 'definition' => \count($this->r),
        ];
    }

    /**
     * @return array
     */
    public function getRequestPool(): array
    {
        return $this->requestPool;
    }

    /**
     * @return array
     */
    public function getSessionPool(): array
    {
        return $this->sessionPool;
    }

    /**
     * @return array
     */
    public function getAliases(): array
    {
        return $this->aliases;
    }

    /**
     * @return array
     */
    public function getClassNames(): array
    {
        return $this->classNames;
    }

    /**
     * @return ObjectDefinition[]
     */
    public function getObjectDefinitions(): array
    {
        return $this->objectDefinitions;
    }

    /**
     * @return ObjectDefinition[]
     */
    public function getRequestDefinitions(): array
    {
        return $this->requestDefinitions;
    }

    /**
     * @return ObjectDefinition[]
     */
    public function getSessionDefinitions(): array
    {
        return $this->sessionDefinitions;
    }

    /**
     * Initialize beans
     *
     * @throws ContainerException
     * @throws ReflectionException
     */
    private function initializeBeans(): void
    {
        // 遍历依赖，初始化bean
        /* @var ObjectDefinition $objectDefinition */
        foreach ($this->objectDefinitions as $beanName => $objectDefinition) {
            $scope = $objectDefinition->getScope();
            // 不初始化request bean
            // Exclude request
            if ($scope === Bean::REQUEST) {
                $this->requestDefinitions[$beanName] = $objectDefinition;
                unset($this->objectDefinitions[$beanName]);
                continue;
            }
    
            // 不初始化session bean
            // Exclude session
            if ($scope === Bean::SESSION) {
                $this->sessionDefinitions[$beanName] = $objectDefinition;
                unset($this->objectDefinitions[$beanName]);
                continue;
            }

            // New bean
            // 初始化bean
            $this->newBean($beanName);
        }
    }

    /**
     * @param string $beanName
     *
     * @return ObjectDefinition
     * @throws ContainerException
     */
    private function getNewObjectDefinition(string $beanName): ObjectDefinition
    {
        if (isset($this->objectDefinitions[$beanName])) {
            return $this->objectDefinitions[$beanName];
        }

        if (isset($this->requestDefinitions[$beanName])) {
            return $this->requestDefinitions[$beanName];
        }

        if (isset($this->sessionDefinitions[$beanName])) {
            return $this->sessionDefinitions[$beanName];
        }

        $classNames = $this->classNames[$beanName] ?? [];
        if (!empty($classNames)) {
            $beanName = end($classNames);
            return $this->getNewObjectDefinition($beanName);
        }

        if (isset($this->aliases[$beanName])) {
            return $this->getNewObjectDefinition($this->aliases[$beanName]);
        }

        throw new ContainerException('Bean name of ' . $beanName . ' is not defined!');
    }

    /**
     * @param string $beanName
     * @param string $scope
     * @param object $object
     * @param string $id
     *
     * @return object
     */
    private function setNewBean(string $beanName, string $scope, $object, string $id = '')
    {
        switch ($scope) {
            case Bean::SINGLETON: // Singleton
                $this->singletonPool[$beanName] = $object;
                break;
            case Bean::PROTOTYPE:
                $this->prototypePool[$beanName] = $object;
                // Clone it
                $object = clone $object;
                break;
            case Bean::REQUEST:
                $this->requestPool[$id][$beanName] = $object;
                break;
            case Bean::SESSION:
                $this->sessionPool[$id][$beanName] = $object;
                break;
        }

        return $object;
    }

    /**
     * Initialize beans
     *
     * @param string $beanName
     * @param string $id
     *
     * @return object
     * @throws ReflectionException
     * @throws ContainerException
     */
    private function newBean(string $beanName, string $id = '')
    {
        // 如果bean已创建则直接获取
        // First, check bean whether has been create.
        if (isset($this->singletonPool[$beanName]) || isset($this->prototypePool[$beanName])) {
            return $this->get($beanName);
        }

        // Get object definition
        // 获取beanName对应的ObjectDefinition最终依赖信息对象
        $objectDefinition = $this->getNewObjectDefinition($beanName);

        $scope     = $objectDefinition->getScope();
        $alias     = $objectDefinition->getAlias();
        $className = $objectDefinition->getClassName();

        // Cache reflection class info
        // 缓存类反射信息
        Reflections::cache($className);

        // Before initialize bean
        // 初始化bean前准备初始化（主要是处理bean的aop切面代理关系）
        $this->beforeInit($beanName, $className, $objectDefinition);
    
        // 获取ObjectDefinition依赖对象中的构造方法依赖
        $constructArgs   = [];
        $constructInject = $objectDefinition->getConstructorInjection();
        if ($constructInject !== null) {
            // 获取构造方法依赖的Params
            $constructArgs = $this->getConstructParams($constructInject, $id);
        }
    
        // 获取ObjectDefinition依赖对象中的属性依赖
        $propertyInjects = $objectDefinition->getPropertyInjections();

        // Proxy class
        // 生成代理类
        if ($this->handler) {
            $className = $this->handler->classProxy($className);
        }
    
        // 通过反射和构造函数依赖参数注入创建反射对象
        $reflectionClass = new ReflectionClass($className);
        $reflectObject   = $this->newInstance($reflectionClass, $constructArgs);

        // Inject properties values
        // 注入属性值依赖
        $this->newProperty($reflectObject, $reflectionClass, $propertyInjects, $id);

        // Alias
        // 设置别名
        if (!empty($alias)) {
            $this->aliases[$alias] = $beanName;
        }

        // Call init method if exist
        // 如果存在init方法，则调用bean对象的init方法
        if ($reflectionClass->hasMethod(self::INIT_METHOD)) {
            $reflectObject->{self::INIT_METHOD}();
        }
    
        // 注册存储bean对象,返回新对象
        return $this->setNewBean($beanName, $scope, $reflectObject, $id);
    }

    /**
     * Get construct args
     *
     * @param MethodInjection $methodInjection
     * @param string          $id
     *
     * @return array
     * @throws ContainerException
     * @throws ReflectionException
     */
    private function getConstructParams(MethodInjection $methodInjection, string $id = ''): array
    {
        $methodName = $methodInjection->getMethodName();
        if ($methodName !== '__construct') {
            throw new ContainerException('ConstructInjection method must be `__construct`');
        }

        $argInjects = $methodInjection->getParameters();
        if (empty($argInjects)) {
            return [];
        }

        $args = [];
        /* @var ArgsInjection $arg */
        foreach ($argInjects as $argInject) {
            // Empty args
            $argValue = $argInject->getValue();
            if (empty($argValue) || !is_string($argValue)) {
                $args[] = $argValue;
                continue;
            }

            $isRef = $argInject->isRef();
            if ($isRef) {
                $argValue = $this->getRefValue($argValue, $id);
            }

            $args[] = $argValue;
        }

        return $args;
    }

    /**
     * New bean instance
     *
     * @param ReflectionClass $reflectionClass
     * @param array           $args
     *
     * @return object
     * @throws ContainerException
     * @throws ReflectionException
     */
    private function newInstance(ReflectionClass $reflectionClass, array $args)
    {
        if (empty($args) || !$reflectionClass->hasMethod('__construct')) {
            return $reflectionClass->newInstance();
        }

        $reflectMethod = $reflectionClass->getMethod('__construct');
        if ($reflectMethod->isPrivate() || $reflectMethod->isProtected()) {
            throw new ContainerException('Construct function for bean must be public!');
        }

        return $reflectionClass->newInstanceArgs($args);
    }

    /**
     * Inject properties into this bean. The properties data from config, annotation
     *
     * @param object          $reflectObject
     * @param ReflectionClass $reflectionClass
     * @param array           $propertyInjects
     * @param string          $id
     *
     * @return void
     * @throws ContainerException
     * @throws ReflectionException
     */
    private function newProperty(
        $reflectObject,
        ReflectionClass $reflectionClass,
        array $propertyInjects,
        string $id = ''
    ): void {
        // New parent properties
        $parentClass = $reflectionClass->getParentClass();
        if ($parentClass !== false) {
            $this->newProperty($reflectObject, $parentClass, $propertyInjects, $id);
        }

        /* @var PropertyInjection $propertyInject */
        foreach ($propertyInjects as $propertyInject) {
            $propertyName = $propertyInject->getPropertyName();
            if (!$reflectionClass->hasProperty($propertyName)) {
                continue;
            }

            $reflectProperty = $reflectionClass->getProperty($propertyName);

            if ($reflectProperty->isStatic()) {
                throw new ContainerException(sprintf('Property %s for bean can not be `static` ', $propertyName));
            }

            // Parse property value
            $propertyValue = $propertyInject->getValue();

            // Inject interface
            if (is_string($propertyValue) && interface_exists($propertyValue)) {
                $propertyValue = InterfaceRegister::getInterfaceInjectBean($propertyValue);
            }

            if (is_array($propertyValue)) {
                $propertyValue = $this->newPropertyArray($propertyValue, $id);
            }

            if ($propertyInject->isRef()) {
                $propertyValue = $this->getRefValue($propertyValue, $id);
            }

            // Parser property type
            $propertyType = ObjectHelper::getPropertyBaseType($reflectProperty);
            if (!empty($propertyType)) {
                $propertyValue = ObjectHelper::parseParamType($propertyType, $propertyValue);
            }

            // First, try set value by setter method
            $setter = 'set' . ucfirst($propertyName);
            if (method_exists($reflectObject, $setter)) {
                $reflectObject->$setter($propertyValue);
                continue;
            }

            if (!$reflectProperty->isPublic()) {
                $reflectProperty->setAccessible(true);
            }

            // Set value by reflection
            $reflectProperty->setValue($reflectObject, $propertyValue);
        }
    }

    /**
     * Before initialize bean
     *
     * @param string           $beanName
     * @param string           $className
     * @param ObjectDefinition $objectDefinition
     */
    private function beforeInit(string $beanName, string $className, ObjectDefinition $objectDefinition): void
    {
        if ($this->handler === null) {
            return;
        }

        $annotation = [];
        foreach ($this->annotations as $ns => $classAnnotations) {
            $annotation = $classAnnotations[$className] ?? $annotation;
        }

        // $annotations = \array_column($this->annotations, $className);
        // $annotation  = $annotations ? $annotations[0] : [];

        $this->handler->beforeInit($beanName, $className, $objectDefinition, $annotation);
    }

    /**
     * New property array
     *
     * @param array  $propertyValue
     * @param string $id
     *
     * @return array
     * @throws ContainerException
     * @throws ReflectionException
     */
    private function newPropertyArray(array $propertyValue, string $id = ''): array
    {
        foreach ($propertyValue as $proKey => &$proValue) {
            if ($proValue instanceof ArgsInjection && $proValue->isRef()) {
                $refValue = $proValue->getValue();
                $proValue = $this->getRefValue($refValue, $id);
            }
        }

        return $propertyValue;
    }

    /**
     * Get ref value
     *
     * @param mixed  $value
     * @param string $id
     *
     * @return mixed
     * @throws ContainerException
     * @throws ReflectionException
     */
    private function getRefValue($value, string $id = '')
    {
        if (!is_string($value)) {
            return $value;
        }

        if (strpos($value, '.') !== 0) {
            return $this->newBean($value, $id);
        }

        // Remove `.`
        $value = substr($value, 1);

        // Other reference
        if ($this->handler !== null) {
            $value = $this->handler->getReferenceValue($value);
        }

        return $value;
    }
}
