<?php
use PHPUnit\Framework\TestCase;

include_once "TestObject.php";

class DataTest extends TestCase
{
    /**
     * @expectedException \InvalidArgumentException
     */
    public function testThrowErrorWhenSetPropertyWithInvalidName()
    {
        $to = new TestObject();
        $to->set;
    }

    /**
     * @expectedException Lempls\SmartObjects\Exceptions\WrongTypeException
     */
    public function testThrowErrorWhenSetInvalidTypeToProperty()
    {
        $to = new TestObject();
        $to->int = 'testString';
    }

    public function testSetAndReadProperty()
    {
        $to = new TestObject();
        $to->string = 'testString';
        $this->assertEquals('testString', $to->string);
    }

    public function testMultipleTypesProperty()
    {
        $to = new TestObject();
        $to->multiple_types = [];
        $to->multiple_types = 'test';
        $this->assertEquals($to->multiple_types, 'test');
    }

    /**
     * @expectedException Lempls\SmartObjects\Exceptions\WrongTypeException
     */
    public function testMultipleTypesPropertyProtect()
    {
        $to = new TestObject();
        $to->multiple_types = 123;
    }

    public function testSetAndReadPropertyWithCustomType()
    {
        $to = new TestObject();
        $to->custom_object = new Lempls\SmartObjects\BaseObject();
        $this->assertInstanceOf(Lempls\SmartObjects\BaseObject::class, $to->custom_object);
    }

    /**
     * @expectedException Lempls\SmartObjects\Exceptions\InvalidAccessException
     */
    public function testProtectReadOnlyProperty()
    {
        $to = new TestObject();
        $to->protected_read = 'testString';
    }

    public function testReadReadOnlyProperty()
    {
        $to = new TestObject();
        $test = $to->protected_read;

        $this->assertEquals($test, $to->protected_read);
    }

    /**
     * @expectedException Lempls\SmartObjects\Exceptions\InvalidAccessException
     */
    public function testProtectWriteOnlyProperty()
    {
        $to = new TestObject();
        $test = $to->protected_write;
    }

    public function testWriteWriteOnlyProperty()
    {
        $to = new TestObject();
        $to->protected_write = 'testString';

        $this->assertTrue(TRUE); // If this didn't work, we would get an exception.
    }

    /**
     * @expectedException Lempls\SmartObjects\Exceptions\InvalidAccessException
     */
    public function testProtectWritePrivateProperty()
    {
        $to = new TestObject();
        $test = $to->protected;
    }

    /**
     * @expectedException Lempls\SmartObjects\Exceptions\InvalidAccessException
     */
    public function testProtectReadPrivateProperty()
    {
        $to = new TestObject();
        $to->protected = 'testString';
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Property multipleTypes must be in snake_case
     */
    public function testCamelCasePropertyNameError()
    {
        $to = new TestObject();
        $to->multipleTypes = 'testString';
    }

    public function testSetPropertyBySetter()
    {
        $to = new TestObject();
        $to->setInt(1);
        $this->assertEquals(1, $to->int);
    }

    public function GetPropertyByGetter()
    {
        $to = new TestObject();
        $to->string = 'testString';

        $test = $to->getString();

        $this->assertEquals('testString', $test);
    }

    public function testGenDoc()
    {
        $this->assertTrue(TestObject::generateDoc());
    }

    public function testCustomSetter()
    {
        $to = new TestObject();
        $to->test = 'testString';

        $this->assertTrue(TRUE); // We are testing custom method.
    }

    public function testCustomGetter()
    {
        $to = new TestObject();

        $this->assertEquals([], $to->test);
    }

    public function testCustomSetterOverProperty()
    {
        $to = new TestObject();
        $to->test_string = 'testString';


        $this->assertEquals('custom', $to->test_string); // We are testing custom method.
    }

    public function testCustomGetterOverProperty()
    {
        $to = new TestObject();

        $this->assertEquals(52, $to->test_int);
    }

    public function testCustomIs()
    {
        $to = new TestObject();

        $this->assertTrue($to->test_boolean);
    }
    
}
