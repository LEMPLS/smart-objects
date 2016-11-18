<?php

/**
 * Test object
 * 
 * 
 * 
 * @package testetsetzseui
 * @property $undefined
 * @property int $int
 * @property int $test_int
 * @property bool $test_boolean
 * @property string $string
 * @property string $test_string
 * @property \Lempls\SmartObjects\BaseObject $custom_object
 * @property array|string $multiple_types
 * @property-read $protected_read
 * @property-write $protected_write
 * @property string $test
 * @property-read $true
 */
class TestObject extends \Lempls\SmartObjects\BaseObject
{

    protected $undefined;

    private   $private;

    /** @var int */
    protected $int;

    /** @var int */
    protected $test_int;

    /** @var bool */
    protected $test_boolean;

    /** @var string */
    protected $string;

    /** @var string */
    protected $test_string;

    /** @var \Lempls\SmartObjects\BaseObject */
    protected $custom_object;

    /** @var array|string */
    protected $multiple_types;

    /** @access read-only */
    protected $protected_read;

    /** @access write-only */
    protected $protected_write;

    /** @access private */
    protected $protected;


    protected function setTest(string $test)
    {

    }

    protected function getTest() : array
    {
        return [];
    }

    protected function isTrue()
    {
        return true;
    }


    protected function customFunction()
    {

    }

    public function setTestString($test_string)
    {
        $this->test_string = 'custom';
    }

    /**
     * @return int
     */
    public function getTestInt(): int
    {
        return 52;
    }

    /**
     * @return bool
     */
    public function isTestBoolean() : bool
    {
        return true;
    }






}
