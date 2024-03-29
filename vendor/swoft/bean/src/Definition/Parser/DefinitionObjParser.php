<?php declare(strict_types=1);

namespace Swoft\Bean\Definition\Parser;

use function array_unique;
use function in_array;
use function is_array;
use function is_string;
use Swoft\Bean\Annotation\Mapping\Bean;
use Swoft\Bean\Definition\ArgsInjection;
use Swoft\Bean\Definition\MethodInjection;
use Swoft\Bean\Definition\ObjectDefinition;
use Swoft\Bean\Definition\PropertyInjection;
use Swoft\Bean\Exception\ContainerException;

/**
 * Class DefinitionParser
 *
 * @since 2.0
 */
class DefinitionObjParser extends ObjectParser
{
    /**
     * Parse definitions
     *
     * @return array
     * @throws ContainerException
     */
    public function parseDefinitions(): array
    {
        // 遍历bean依赖信息
        foreach ($this->definitions as $beanName => $definition) {
            // 已存在依赖信息时进行重置
            if (isset($this->objectDefinitions[$beanName])) {
                $objectDefinition = $this->objectDefinitions[$beanName];
                $this->resetObjectDefinition($beanName, $objectDefinition, $definition);
                continue;
            }
            
            // 不存在时创建依赖关系
            $this->createObjectDefinition($beanName, $definition);
        }

        return [$this->definitions, $this->objectDefinitions, $this->classNames, $this->aliases];
    }

    /**
     * Reset object definition
     *
     * @param string           $beanName
     * @param ObjectDefinition $objDefinition
     * @param array            $definition
     *
     * @throws ContainerException
     */
    private function resetObjectDefinition(string $beanName, ObjectDefinition $objDefinition, array $definition): void
    {
        // Parse class name
        $className    = $definition['class'] ?? '';
        $objClassName = $objDefinition->getClassName();

        if (!empty($className) && $className !== $objClassName) {
            throw new ContainerException('Class for annotations and definitions must be the same Or not to define class');
        }

        $objDefinition = $this->updateObjectDefinitionByDefinition($objDefinition, $definition);

        $this->objectDefinitions[$beanName] = $objDefinition;
    }

    /**
     * Create object definition for definition
     *
     * @param string $beanName
     * @param array  $definition
     *
     * @throws ContainerException
     */
    private function createObjectDefinition(string $beanName, array $definition): void
    {
        $className = $definition['class'] ?? '';
        if (empty($className)) {
            throw new ContainerException(sprintf('%s key for definition must be defined class', $beanName));
        }
        
        // 初始化ObjectDefinition
        $objDefinition = new ObjectDefinition($beanName, $className);
    
        // 更新收集好的依赖 $definition 到ObjectDefinition对象
        $objDefinition = $this->updateObjectDefinitionByDefinition($objDefinition, $definition);

        $classNames   = $this->classNames[$className] ?? [];
        $classNames[] = $beanName;

        $this->classNames[$className]       = array_unique($classNames);
    
        // 存储对象依赖信息
        // beanName => ObjectDefinition
        $this->objectDefinitions[$beanName] = $objDefinition;
    }

    /**
     * Parse definition
     *
     * @param array $definition
     *
     * @return array
     * @throws ContainerException
     */
    private function parseDefinition(array $definition): array
    {
        // Remove class key
        unset($definition['class']);

        // Parse construct
        $constructArgs = $definition[0] ?? [];
        if (!is_array($constructArgs)) {
            throw new ContainerException('Construct args for definition must be array');
        }

        // Parse construct args
        $argInjects = [];
        foreach ($constructArgs as $arg) {
            [$argValue, $argIsRef] = $this->getValueByRef($arg);

            $argInjects[] = new ArgsInjection($argValue, $argIsRef);
        }

        // Set construct inject
        $constructInject = null;
        if (!empty($argInjects)) {
            $constructInject = new MethodInjection('__construct', $argInjects);
        }

        // Remove construct definition
        unset($definition[0]);

        // Parse definition option
        $option = $definition['__option'] ?? [];
        if (!is_array($option)) {
            throw new ContainerException('__option for definition must be array');
        }

        // Remove `__option`
        unset($definition['__option']);

        // Parse definition properties
        $propertyInjects = [];
        foreach ($definition as $propertyName => $propertyValue) {
            if (!is_string($propertyName)) {
                throw new ContainerException('Property key from definition must be string');
            }

            [$proValue, $proIsRef] = $this->getValueByRef($propertyValue);

            // Parse property for array
            if (is_array($proValue)) {
                $proValue = $this->parseArrayProperty($proValue);
            }

            $propertyInject = new PropertyInjection($propertyName, $proValue, $proIsRef);

            $propertyInjects[$propertyName] = $propertyInject;
        }

        return [$constructInject, $propertyInjects, $option];
    }

    /**
     * Update definition
     *
     * @param ObjectDefinition $objDfn
     * @param array            $definition
     *
     * @return ObjectDefinition
     * @throws ContainerException
     */
    private function updateObjectDefinitionByDefinition(ObjectDefinition $objDfn, array $definition): ObjectDefinition
    {
        // 将依赖解析成
        // 构造方法ArgsInjection对象
        // 属性PropertyInjection对象
        // 以及 $option
        [$constructInject, $propertyInjects, $option] = $this->parseDefinition($definition);
    
        // 设置构造方法依赖
        // Set construct inject
        if (!empty($constructInject)) {
            $objDfn->setConstructorInjection($constructInject);
        }
    
        // 设置属性依赖
        // Set property inject
        foreach ($propertyInjects as $propertyName => $propertyInject) {
            $objDfn->setPropertyInjection($propertyName, $propertyInject);
        }

        $scopes = [
            Bean::SINGLETON,
            Bean::PROTOTYPE,
            Bean::REQUEST,
        ];

        $scope = $option['scope'] ?? '';
        $alias = $option['alias'] ?? '';

        if (!empty($scope) && !in_array($scope, $scopes, true)) {
            throw new ContainerException('Scope for definition is not undefined');
        }

        // Update scope
        // 更新作用域
        if (!empty($scope)) {
            $objDfn->setScope($scope);
        }

        // Update alias
        // 更新别名
        if (!empty($alias)) {
            $objDfn->setAlias($alias);

            $objAlias = $objDfn->getAlias();
            unset($this->aliases[$objAlias]);

            $this->aliases[$alias] = $objDfn->getName();
        }

        return $objDfn;
    }

    /**
     * Parse array property
     *
     * @param array $propertyValue
     *
     * @return array
     */
    private function parseArrayProperty(array $propertyValue): array
    {
        foreach ($propertyValue as $proKey => &$proValue) {
            [$refValue, $isRef] = $this->getValueByRef($proValue);
            if (!$isRef) {
                continue;
            }

            $proValue = new ArgsInjection($refValue, $isRef);
        }

        return $propertyValue;
    }
}
