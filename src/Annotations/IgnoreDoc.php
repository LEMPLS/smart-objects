<?php
/**
 * Created by IntelliJ IDEA.
 * User: Puma
 * Date: 13.11.2016
 * Time: 10:39
 */

namespace Lempls\SmartObjects\Annotations;


use phpDocumentor\Reflection\DocBlock\Tags\BaseTag;

/**
 * Class IgnoreDoc
 *
 * @Annotation
 * @package Lempls\SmartObjects\Annotations
 */
class IgnoreDoc extends BaseTag
{

    /** @var string Name of the tag */
    protected $name = 'ignoreDoc';

    /** @var Description|null Description of the tag. */
    protected $description;

    /**
     * Gets the name of this tag.
     *
     * @return string The name of this tag.
     */
    public function getName()
    {
        return $this->name;
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function render(\phpDocumentor\Reflection\DocBlock\Tags\Formatter $formatter = null)
    {
        if ($formatter === null) {
            $formatter = new Formatter\PassthroughFormatter();
        }

        return $formatter->format($this);
    }


    public static function create($body)
    {
        parent::create($body);
    }


    public function __toString()
    {
        return parent::__toString();
    }

}
