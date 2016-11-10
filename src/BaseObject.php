<?php
/**
 *
 * Most basic helper class, allows for dynamic access to properties but allows us to type-hint their type.
 *
 * @author Martin Kapal <flamecze@gmail.com>
 * @author Tomáš Korený <tom@koreny.eu>
 */

namespace Lempls\SmartObjects;


use Lempls\SmartObjects\Exceptions;
use phpDocumentor\Reflection\Type;
use phpDocumentor\Reflection\Types\Compound;
use phpDocumentor\Reflection\Types\Mixed;
use phpDocumentor\Reflection\Types\Object_;



class BaseObject
{

    /**
     * Defining all of our possible accesses.
     */
    const ACCESS_PUBLIC = 'public';
    const ACCESS_PRIVATE = 'private';
    const ACCESS_READ_ONLY = 'read-only';
    const ACCESS_WRITE_ONLY = 'write-only';

    /**
     * We map shorter strings to longer.
     */
    const TYPES = ['int' => 'integer', 'bool' => 'boolean', 'float' => 'double'];

    /**
     * @param string $key
     * @return mixed
     * @throws Exceptions\InvalidAccessException In case of property being protected
     */
    public function __get($key)
    {
        if (gettype($key) !== 'string') throw new \InvalidArgumentException('Property name must be string'); // Stupid instead of typehint, ensures compatability with doctrine proxies
        if ($this->hasReadAccess($key)) {
            $getter = $this->getGetter($key);
            if ($getter !== false) {
                return $this->$getter();
            }
            return $this->$key;
        } else {
            throw new Exceptions\InvalidAccessException('Invalid access');
        }
    }

    /**
     * @param string $key
     * @param mixed $value
     * @throws Exceptions\InvalidAccessException In case of property being protected
     * @throws Exceptions\WrongTypeException In case of trying to pass different value then type-hinted
     */
    public function __set($key, $value)
    {
        if (gettype($key) !== 'string') throw new \InvalidArgumentException('Property name must be string'); // Stupid instead of typehint, ensures compatability with doctrine proxies
        if ($this->hasWriteAccess($key)) {
            $setter = $this->getSetter($key);
            if ($setter != null) {
                $this->$setter($value);
                return;
            }
            $annotation = $this->readAnnotation($key, 'var');
            if ($annotation === false) {
                $this->$key = $value;
                return;
            }
            $type = $this->readAnnotation($key, 'var')->getType();
            if ($type instanceof \phpDocumentor\Reflection\Types\Compound) {
                foreach ($this->compoundToArray($type) as $t) {
                    if ($this->setVerifiedProperty($key, $value, $t)) {
                        return;
                    }
                }
                throw new Exceptions\WrongTypeException('Property received different value then type-hinted');
            } else if (!$this->setVerifiedProperty($key, $value, $type)) {
                throw new Exceptions\WrongTypeException('Property received different value then type-hinted');
            }
            return;
        } else {
            throw new Exceptions\InvalidAccessException('Invalid access');
        }
    }

    public function __call($name, $args)
    {
        list($prefix, $name) = $this->methodToPropertyName($name);
        switch ($prefix) {
            case 'set': $this->__set($name, $args[0]); break;
            case 'get': case 'is': $this->__get($name); break;
            case 'has': $this->__isset($name); break;
            case 'add':
                if ($this->$name instanceof Doctrine\Common\Collections\ArrayCollection || gettype($this->$name) === 'array') {
                    $this->$name[] = $args[0];
                };
             break;
            case 'rem':
                if ($this->$name instanceof Doctrine\Common\Collections\ArrayCollection || gettype($this->$name) === 'array') {
                    $this->$name->removeElement($args[0]);
                };
             break;
            
        }

    }


    private function methodToPropertyName($method)
    {
        $prefixes = ['set','get','is','has','add','rem'];
        foreach ($prefixes as $prefix) {
            if (strpos($method, $prefix) === 0 && strlen($method) > strlen($prefix)) {
                $name = substr($method, strlen($prefix)-1);
                $words = preg_split('/(?=[A-Z])/', $name);
                if(!isset($words[0])) return false;

                $name = implode('_', $words);


                return [$prefix, $name];
            }
        }
    }

    /**
     * Magic isset
     *
     * @param $key
     * @return bool
     * @throws Exceptions\InvalidAccessException
     */
    public function __isset($key)
    {
        if (gettype($key) !== 'string') throw new \InvalidArgumentException('Property name must be string'); // Stupid instead of typehint, ensures compatability with doctrine proxies
        if ($this->hasReadAccess($key) || $this->hasWriteAccess($key)) {
            return isset($this->$key);
        } else {
            throw new Exceptions\InvalidAccessException('Invalid access');
        }
    }

    /**
     * @param Type $type
     * @param mixed $value
     * @return bool Returns true when type corresponds with value
     */
    private function verifyType(Type $type, $value) : bool
    {
        if ($type instanceof Object_) {
            if ($type->getFqsen() === null) return true;
            $classname = '\\' . $value->getClass();
            return $type->__toString() === $classname || $type->__toString() === $value->getClass();
        } elseif ($type instanceof Mixed) {
            return true;
        } else {
            $type_string = $type->__toString();
            foreach (self::TYPES as $short => $long) {
                if ($type_string === $short) {
                    $type_string = $long;
                    break;
                }
            }
            return gettype($value) === $type_string;
        }

    }

    /**
     * @param string $key
     * @return bool
     */
    private function hasReadAccess(string $key) : bool
    {
        $tag = $this->readAnnotation($key, 'access');
        if ($tag == null) return true;

        $access = $tag->getDescription()->render();

        return $access == self::ACCESS_READ_ONLY || $access == self::ACCESS_PUBLIC;
    }

    /**
     * @param string $key
     * @return bool
     */
    private function hasWriteAccess(string $key) : bool
    {
        $tag = $this->readAnnotation($key, 'access');
        if ($tag == null) return true;

        $access = $tag->getDescription()->render();

        return $access == self::ACCESS_WRITE_ONLY || $access == self::ACCESS_PUBLIC;
    }

    /**
     * @param string $property
     * @param string $annotation
     * @return false|\phpDocumentor\Reflection\DocBlock\Tag
     */
    protected function readAnnotation(string $property, string $annotation)
    {
        $factory = \phpDocumentor\Reflection\DocBlockFactory::createInstance();
        $docblock = $factory->create(new \ReflectionProperty($this->getClass(), $property));
        $tag = $docblock->getTagsByName($annotation);
        if (count($tag) == 0 || !isset($tag[0])) return false;
        return $tag[0];
    }

    /**
     * Little hack to count exact number of possible Types to get.
     *
     * @param Compound $compound
     * @return array
     */
    private function compoundToArray(Compound $compound) : array
    {
        $array = explode('|', (string)$compound);
        $return = [];
        foreach ($array as $key => $value) {
            $return[] = $compound->get($key);
        }
        return $return;
    }


    /**
     * @param string $key
     * @param mixed $value
     * @param Type $type
     * @return bool
     */
    private function setVerifiedProperty(string $key, $value, Type $type) : bool
    {
        if ($this->verifyType($type, $value)) {
            $this->$key = $value;
            return true;
        } else {
            return false;
        }
    }

    /**
     * Creates array of all readable properties.
     *
     * @param array $exclude List of all excluded properties
     * @return array
     */
    public function toArray($exclude = []) : array
    {
        $array = [];
        foreach ($this as $key => $value) {
            if (!in_array($key, $exclude)) {

                if ($this->hasReadAccess($key)) {
                    $array[$key] = $this->__get($key);
                }
            }
        }
        return $array;
    }

    /**
     * Creates json of all readable properties.
     *
     * @param array $exclude List of all excluded properties
     * @return string
     */
    public function toJson($exclude = []) : string
    {
        return json_encode($this->toArray($exclude));
    }

    /**
     * If class has implementer property setter, we will use it
     *
     * @param string $property
     * @return bool|string
     */
    public function getSetter(string $property)
    {
        $method = 'set' . str_replace('_', '', ucwords($property, '_'));
        if (method_exists($this, $method)) {
            return $method;
        }
        return false;
    }

    /**
     * If class has implementer property getter, we will use it
     *
     * @param string $property
     * @return bool|string
     */
    public function getGetter(string $property)
    {
        $method = 'get' . str_replace('_', '', ucwords($property, '_'));
        if (method_exists($this, $method)) {
            return $method;
        }
        return false;
    }

    public function getClass()
    {
        return get_called_class();
    }

}
