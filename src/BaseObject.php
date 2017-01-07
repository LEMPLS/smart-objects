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
use Lempls\SmartObjects\Annotations\IgnoreDoc;
use phpDocumentor\Reflection\Type;
use phpDocumentor\Reflection\Types\Compound;
use phpDocumentor\Reflection\Types\Mixed;
use phpDocumentor\Reflection\Types\Object_;
use PhpParser\Error;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\ParserFactory;


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
    public function  __get($key)
    {
        if (gettype($key) !== 'string') throw new \InvalidArgumentException('Property name must be string, ' . gettype($key) . 'given.'); // Stupid instead of typehint, ensures compatability with doctrine proxies
        $getter = $this->getGetter($key);
        if ($getter) {
            return $this->$getter();
        } elseif ($this->propertyExists($key)) {
            if ($this->hasReadAccess($key)) {
                return $this->$key;
            } else {
                throw new Exceptions\InvalidAccessException('Tried accessing protected property ' . $key . '.');
            }
        } else {
            throw new Exceptions\InvalidAccessException('Tried accessing non-existing property ' . $key . '.');
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
        $setter = self::getSetter($key);
        if ($setter) {
            $this->$setter($value);
        } elseif ($this->propertyExists($key)) {
            if ($this->hasWriteAccess($key)) {

                $annotation = $this->readPropertyAnnotation($key, 'var');
                if ($annotation === false) {
                    $this->$key = $value;
                    return;
                }

                $type = $this->readPropertyAnnotation($key, 'var')->getType();
                if ($type instanceof \phpDocumentor\Reflection\Types\Compound) {
                    foreach ($this::compoundToArray($type) as $t) {
                        if ($this->setVerifiedProperty($key, $value, $t)) {
                            return;
                        }
                    }
                    throw new Exceptions\WrongTypeException(sprintf("Property %s received %s instead of one of type-hinted", $key, gettype($value)));
                } else if (!$this->setVerifiedProperty($key, $value, $type)) {
                    throw new Exceptions\WrongTypeException(sprintf("Property %s received %s instead of type-hinted %s", $key, gettype($value), $type));
                }
                return;
            } else {
                throw new Exceptions\InvalidAccessException('Invalid access to ' . $key);
            }
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
                // TODO : not working
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


    private static function methodToPropertyName($method)
    {
        $method = self::decamelize($method);
        $prefixes = ['set','get','is','has','add','rem'];
        foreach ($prefixes as $prefix) {
            if (strpos($method, $prefix) === 0 && strlen($method) > strlen($prefix)) {
                $name = substr($method, strlen($prefix));
                $words = explode('_', $method);
                if(!isset($words[0])) return false;
                unset($words[0]);
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
        if($this->getGetter($key) || $this->getSetter($key)) {
            return true;
        } elseif ($this->propertyExists($key)) {
            if ($this->hasReadAccess($key) || $this->hasWriteAccess($key)) {
                return true;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    /**
     * @param Type $type
     * @param mixed $value
     * @return bool Returns true when type corresponds with value
     */
    private static function verifyType(Type $type, $value) : bool
    {
        if ($type instanceof Object_) {
            if ($type->getFqsen() === null) return true; // TODO : Get fully qualified class name
            if (!method_exists($value, 'getClass')) return true; // TODO : Get $value::class
            $classname = '\\' . $value->getClass();

            // TODO : Doctrine returns proxies instead of original entities
            $proxies      = '\\DoctrineProxies\\__CG__\\';
            $proxies_pos  = strpos($classname, $proxies);
            if ($proxies_pos !== false) {
                $classname = substr($classname, $proxies_pos + strlen($proxies) - 1);
            }

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
    private static function hasReadAccess(string $key) : bool
    {
        if (property_exists(self::getClass(), $key)) {
            $tag = self::readPropertyAnnotation($key, 'access');
            if ($tag == null) return true;

            $access = $tag->getDescription()->render();

            return $access == self::ACCESS_READ_ONLY || $access == self::ACCESS_PUBLIC;
        }
        else {
            return false;
        }
    }

    /**
     * @param string $key
     * @return bool
     */
    private static function hasWriteAccess(string $key) : bool
    {
        if (property_exists(self::getClass(), $key)) {
            $tag = self::readPropertyAnnotation($key, 'access');
            if ($tag == null) return true;

            $access = $tag->getDescription()->render();

            return $access == self::ACCESS_WRITE_ONLY || $access == self::ACCESS_PUBLIC;
        }
        else {
            return false;
        }
    }

    /**
     * @param string $property
     * @param string $annotation
     * @return false|\phpDocumentor\Reflection\DocBlock\Tag
     */
    protected static function readPropertyAnnotation(string $property, string $annotation)
    {
        $factory = \phpDocumentor\Reflection\DocBlockFactory::createInstance();
        $reflectionProperty = new \ReflectionProperty(self::getClass(), $property);
        if($reflectionProperty->getDocComment() == false) return false;
        $docblock = $factory->create($reflectionProperty->getDocComment());
        $tag = $docblock->getTagsByName($annotation);
        if (count($tag) == 0 || !isset($tag[0])) return false;
        return $tag[0];
    }

    /**
     * @param string $property
     * @param string $annotation
     * @return false|\phpDocumentor\Reflection\DocBlock\Tag
     */
    protected static function readMethodAnnotation(string $method, string $annotation)
    {
        $factory = \phpDocumentor\Reflection\DocBlockFactory::createInstance();
        $reflectionMethod = new \ReflectionMethod(self::getClass(), $method);
        if($reflectionMethod->getDocComment() == false) return false;
        $docblock = $factory->create($reflectionMethod->getDocComment());
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
    private static function compoundToArray(Compound $compound) : array
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


//    /**
//     * Set new values from array.
//     *
//     * @param array $values
//     * @param bool  $allow_empty_strings
//     */
//    public function setValues(array $values, bool $allow_empty_strings = true)
//    {
//        foreach ($values as $key => $value) {
//            if (!$allow_empty_strings && $value === "") {
//                continue;
//            }
//            $this->__set($key, $value);
//        }
//    }


    /**
     * If class has implementer property setter, we will use it
     *
     * @ignoreDoc
     * @param string $property
     * @return bool|string
     */
    private static function getSetter(string $property)
    {
        try {
            if (self::propertyExists($property)) {
                if (!self::hasWriteAccess($property)) return false;
            }
        } catch (\InvalidArgumentException $e) {

        };
        $method = ucfirst(self::camelize($property));
        if (method_exists(self::getClass(), 'set' . $method)) {
            return 'set' . $method;
        }
        return false;
    }

    /**
     * If class has implementer property getter, we will use it
     *
     * @ignoreDoc
     * @param string $property
     * @return bool|string
     */
    private static function getGetter(string $property)
    {
        try {
            if (self::propertyExists($property)) {
                if (!self::hasReadAccess($property)) return false;
            }
        } catch (\InvalidArgumentException $e) {

        };
        $method = ucfirst(self::camelize($property));
        if (method_exists(self::getClass(), 'get' . $method)) {
            return 'get' . $method;
        } elseif (method_exists(self::getClass(), 'is' . $method)) {
            return 'is' . $method;
        }
        return false;
    }

    private static function getClass()
    {
        return get_called_class();
    }

    private static function propertyExists($property)
    {
        if (property_exists(self::getClass(), $property)) {
            return true;
        } elseif (property_exists(self::getClass(), self::decamelize($property))) {
            throw new \InvalidArgumentException('Property ' . $property . ' must be in snake_case. (Hint: use ' . self::decamelize($property) . ' ;) )');
        } else {
            return false;
        }
    }

    private static function decamelize($word) {
        return strtolower(preg_replace(['/([a-z\d])([A-Z])/', '/([^_])([A-Z][a-z])/'], '$1_$2', $word));
    }

    private static function camelize($word) {
        return $word = preg_replace_callback(
            "/(^|_)([a-z])/",
            function($m) { return strtoupper("$m[2]"); },
            $word
        );
    }

    private static function prepareDoc()
    {
        $class = new \ReflectionClass(get_called_class());
        $properties = [];

        foreach ($class->getProperties() as $property) {
            if (self::readPropertyAnnotation($property->getName(), 'ignoreDoc') === false) {
                if (!$property->isPrivate()) {
                    $p = ['name' => $property->getName(), 'type' => null];
                    if (self::hasReadAccess($property->getName())) {
                        $p['read'] = true;
                    }
                    if (self::hasWriteAccess($property->getName())) {
                        $p['write'] = true;
                    }
                    if (self::readPropertyAnnotation($property->getName(), 'var') !== false) {
                        $p['type'] = self::readPropertyAnnotation($property->getName(), 'var')->getType();
                    }
                    $properties[] = $p;
                }
            }
        }

        foreach ($class->getMethods() as $method) {
            $computed_property = self::methodToPropertyName($method->getName());
            if ($computed_property !== null && !$method->isPrivate()) {
                $factory = \phpDocumentor\Reflection\DocBlockFactory::createInstance();
                if (self::readMethodAnnotation($method->getName(), 'ignoreDoc') === false) {
                    $p = ['name' => $computed_property[1], 'type' => null];
                    if ($method->getDocComment() !== false) { //try to read @var on setter/getter
                        $docblock = $factory->create($method->getDocComment());
                        $tag = $docblock->getTagsByName('var');
                        if (count($tag) !== 0 && isset($tag[0])) {
                            $p['type'] = $tag[0];
                        }
                    }

                    if ($computed_property[0] == 'get' || $computed_property[0] == 'is') {
                        $rf = self::recursiveFind($computed_property[1], $properties, 'name');
                        if ($rf === false) {
                            $p['read'] = true;
                            $reflectionType = $method->getReturnType();
                            if ($reflectionType !== null) $p['type'] = $reflectionType->__toString() ;
                        } else {
                            $properties[$rf]['read'] = true;
                            continue;
                        }
                    }
                    if ($computed_property[0] == 'set') {
                        $rf = self::recursiveFind($computed_property[1], $properties, 'name');
                        if ($rf === false) {
                            $p['write'] = true;
                            $reflectionType = $method->getParameters()[0]->getType();
                            if ($reflectionType !== null) $p['type'] = $reflectionType->__toString() ;
                        } else {
                            $properties[$rf]['write'] = true;
                            continue;
                        }
                    }
                    $properties[] = $p;
                }
            }
        }

        return $properties;
    }

    /**
     * @param $needle
     * @param $haystack
     * @param $name
     * @return bool|int|string
     */
    private static function recursiveFind($needle, $haystack, $name)
    {
        foreach ($haystack as $key => $test) {
            if ($test[$name] === $needle) {
                return $key;
            }
        }
        return false;
    }

    public static function generateDoc()
    {
        $class = new \ReflectionClass(get_called_class());
        $factory = \phpDocumentor\Reflection\DocBlockFactory::createInstance();

        $doc_comment = $class->getDocComment();


        $doc_new[] = "/**";

        if ($doc_comment) {
            $doc = $factory->create($class);
            $tags = $doc->getTags();
            $tags_new = $doc->getTags();

            foreach ($tags as $id => $tag) {
                if (in_array($tag->getName(), array('property', 'property-read', 'property-write'))) {
                    unset($tags_new[$id]);
                }
            }

            $doc_new[] = " * " . $doc->getSummary();
            $doc_new[] = " * ";
            $doc_new[] = " * " . $doc->getDescription();
            $doc_new[] = " * ";

            foreach ($tags_new as $tag) {
                $doc_new[] = " * " . $tag->render();
            }
        }

        $properties = self::prepareDoc();

        foreach ($properties as $property) {
            if (isset($property['read']) && isset($property['write'])) {
                $doc_new[] = " * " .  '@property ' . (strlen($property['type']) > 0 ? $property['type'] . ' ' : '') . '$' . $property['name'];
            } elseif (isset($property['read'])) {
                $doc_new[] = " * " . '@property-read ' . (strlen($property['type']) > 0 ? $property['type'] . ' ' : '') . '$' . $property['name'];
            } elseif (isset($property['write'])) {
                $doc_new[] = " * " . '@property-write ' . (strlen($property['type']) > 0 ? $property['type'] . ' ' : '') . '$' . $property['name'];
            }
        }

        $doc_new[] = " */";

        $content = file_get_contents($class->getFileName());

        if ($doc_comment) {
            $content = str_replace($class->getDocComment(), implode(PHP_EOL, $doc_new), $content);
            file_put_contents($class->getFileName(), $content);
        } else {
            $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
            try {
                $stmts = $parser->parse($content);
                $class_line = 1;
                foreach ($stmts as $stmt) {
                    if ($stmt instanceof Namespace_) {
                        foreach ($stmt as $ns_stmt) {
                            if ($ns_stmt instanceof Class_) {
                                $class_line = $ns_stmt->getAttribute('startLine');
                            }
                        }
                    } elseif ($stmt instanceof Class_) {
                        $class_line = $stmt->getAttribute('startLine');
                    }
                }

                $content_lines = explode(PHP_EOL, $content);
                array_splice($content_lines, $class_line-1, 0, $doc_new);
                file_put_contents($class->getFileName(), implode(PHP_EOL, $content_lines));

            } catch (Error $e) {
                return false;
            }
        }

        return true;
    }

}
