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
    public function __get(string $key)
    {
        if ($this->hasReadAccess($key)) {
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
    public function __set(string $key, $value)
    {
        if ($this->hasWriteAccess($key)) {
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
        $access = $this->readAnnotation($key, 'access')->getDescription()->render();

        return $access == self::ACCESS_READ_ONLY || $access == self::ACCESS_PUBLIC;
    }

    /**
     * @param string $key
     * @return bool
     */
    private function hasWriteAccess(string $key) : bool
    {
        $access = $this->readAnnotation($key, 'access')->getDescription()->render();

        return $access == self::ACCESS_WRITE_ONLY || $access == self::ACCESS_PUBLIC;
    }

    /**
     * @param string $property
     * @param string $annotation
     * @return false|\phpDocumentor\Reflection\DocBlock\Tag
     */
    private function readAnnotation(string $property, string $annotation)
    {
        $factory = \phpDocumentor\Reflection\DocBlockFactory::createInstance();
        $docblock = $factory->create(new \ReflectionProperty(self::class, $property));
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
     * @return array
     */
    public function toArray() : array
    {
        $array = [];
        foreach ($this as $key => $value) {
            if ($this->hasReadAccess($key)) {
                $array[$key] = $value;
            }
        }
        return $array;
    }

    /**
     * Creates json of all readable properties.
     *
     * @return string
     */
    public function toJson() : string
    {
        return json_encode($this->toArray());
    }

}
