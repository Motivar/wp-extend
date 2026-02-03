<?php
/**
 * Template System Foundation Test
 * 
 * Tests the basic functionality of the template system foundation
 * including class loading, instantiation, and basic interface compliance.
 * 
 * @since 1.1.3
 * @package EWP\Tests
 */

use PHPUnit\Framework\TestCase;
use EWP\TemplateSystem\AWM_Template_System;
use EWP\TemplateSystem\AWM_Template_Resolver;
use EWP\TemplateSystem\AWM_Template_Locator;
use EWP\TemplateSystem\AWM_Template_Cache;
use EWP\TemplateSystem\AWM_Variable_Sanitizer;
use EWP\TemplateSystem\AWM_Template_Parser;
use EWP\TemplateSystem\AWM_Template_Variable_Preparer;
use EWP\TemplateSystem\AWM_Template_Error_Handler;

class TemplateSystemFoundationTest extends TestCase
{
    /**
     * Test that all template system interfaces exist
     */
    public function test_interfaces_exist()
    {
        $this->assertTrue(interface_exists('EWP\\TemplateSystem\\Interfaces\\Template_Cache_Interface'));
        $this->assertTrue(interface_exists('EWP\\TemplateSystem\\Interfaces\\Template_Locator_Interface'));
        $this->assertTrue(interface_exists('EWP\\TemplateSystem\\Interfaces\\Template_Parser_Interface'));
        $this->assertTrue(interface_exists('EWP\\TemplateSystem\\Interfaces\\Template_Renderer_Interface'));
        $this->assertTrue(interface_exists('EWP\\TemplateSystem\\Interfaces\\Variable_Sanitizer_Interface'));
    }

    /**
     * Test that all template system classes exist
     */
    public function test_classes_exist()
    {
        $this->assertTrue(class_exists('EWP\\TemplateSystem\\AWM_Template_System'));
        $this->assertTrue(class_exists('EWP\\TemplateSystem\\AWM_Template_Resolver'));
        $this->assertTrue(class_exists('EWP\\TemplateSystem\\AWM_Template_Locator'));
        $this->assertTrue(class_exists('EWP\\TemplateSystem\\AWM_Template_Cache'));
        $this->assertTrue(class_exists('EWP\\TemplateSystem\\AWM_Variable_Sanitizer'));
        $this->assertTrue(class_exists('EWP\\TemplateSystem\\AWM_Template_Parser'));
        $this->assertTrue(class_exists('EWP\\TemplateSystem\\AWM_Template_Variable_Preparer'));
        $this->assertTrue(class_exists('EWP\\TemplateSystem\\AWM_Template_Error_Handler'));
    }

    /**
     * Test that classes can be instantiated
     */
    public function test_classes_can_be_instantiated()
    {
        $locator = new AWM_Template_Locator();
        $this->assertInstanceOf(AWM_Template_Locator::class, $locator);

        $cache = new AWM_Template_Cache();
        $this->assertInstanceOf(AWM_Template_Cache::class, $cache);

        $sanitizer = new AWM_Variable_Sanitizer();
        $this->assertInstanceOf(AWM_Variable_Sanitizer::class, $sanitizer);

        $parser = new AWM_Template_Parser();
        $this->assertInstanceOf(AWM_Template_Parser::class, $parser);

        $preparer = new AWM_Template_Variable_Preparer();
        $this->assertInstanceOf(AWM_Template_Variable_Preparer::class, $preparer);

        $error_handler = new AWM_Template_Error_Handler();
        $this->assertInstanceOf(AWM_Template_Error_Handler::class, $error_handler);

        $resolver = new AWM_Template_Resolver();
        $this->assertInstanceOf(AWM_Template_Resolver::class, $resolver);
    }

    /**
     * Test that classes implement their respective interfaces
     */
    public function test_classes_implement_interfaces()
    {
        $locator = new AWM_Template_Locator();
        $this->assertInstanceOf('EWP\\TemplateSystem\\Interfaces\\Template_Locator_Interface', $locator);

        $cache = new AWM_Template_Cache();
        $this->assertInstanceOf('EWP\\TemplateSystem\\Interfaces\\Template_Cache_Interface', $cache);

        $sanitizer = new AWM_Variable_Sanitizer();
        $this->assertInstanceOf('EWP\\TemplateSystem\\Interfaces\\Variable_Sanitizer_Interface', $sanitizer);

        $parser = new AWM_Template_Parser();
        $this->assertInstanceOf('EWP\\TemplateSystem\\Interfaces\\Template_Parser_Interface', $parser);

        $resolver = new AWM_Template_Resolver();
        $this->assertInstanceOf('EWP\\TemplateSystem\\Interfaces\\Template_Renderer_Interface', $resolver);
    }

    /**
     * Test that AWM_Template_System can be initialized
     */
    public function test_template_system_initialization()
    {
        // Test static initialization
        AWM_Template_System::init();
        
        // Test that resolver can be retrieved
        $resolver = AWM_Template_System::get_resolver();
        $this->assertInstanceOf(AWM_Template_Resolver::class, $resolver);
    }

    /**
     * Test basic template system functionality
     */
    public function test_basic_template_system_functionality()
    {
        $resolver = new AWM_Template_Resolver();
        
        // Test fallback mode
        $resolver->set_fallback_mode(true);
        $this->assertTrue($resolver->get_fallback_mode());
        
        $resolver->set_fallback_mode(false);
        $this->assertFalse($resolver->get_fallback_mode());
        
        // Test cache clearing (should not throw errors)
        $resolver->clear_cache();
        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    /**
     * Test template locator hierarchy
     */
    public function test_template_locator_hierarchy()
    {
        $locator = new AWM_Template_Locator();
        
        // Test hierarchy generation
        $hierarchy = $locator->get_template_hierarchy('test.php');
        $this->assertIsArray($hierarchy);
        $this->assertNotEmpty($hierarchy);
        
        // Should have at least the core plugin template path
        $this->assertStringContains('templates/frontend/inputs/test.php', end($hierarchy));
    }

    /**
     * Test variable sanitizer basic functionality
     */
    public function test_variable_sanitizer_basic_functionality()
    {
        $sanitizer = new AWM_Variable_Sanitizer();
        
        // Test HTML escaping
        $escaped = $sanitizer->escape_for_html('<script>alert("test")</script>');
        $this->assertStringNotContains('<script>', $escaped);
        
        // Test attribute escaping
        $escaped = $sanitizer->escape_for_attribute('test"value');
        $this->assertStringNotContains('"', $escaped);
    }

    /**
     * Test template variable preparer basic functionality
     */
    public function test_template_variable_preparer_basic_functionality()
    {
        $preparer = new AWM_Template_Variable_Preparer();
        
        $field_config = [
            'case' => 'input',
            'type' => 'text',
            'label' => 'Test Field',
            'name' => 'test_field'
        ];
        
        $context = [
            'original_meta' => 'test_field',
            'view' => 'post'
        ];
        
        $variables = $preparer->prepare_variables($field_config, 'test_value', $context);
        
        $this->assertIsArray($variables);
        $this->assertArrayHasKey('field', $variables);
        $this->assertArrayHasKey('context', $variables);
        $this->assertArrayHasKey('value', $variables);
        $this->assertArrayHasKey('input_name', $variables);
        $this->assertArrayHasKey('input_id', $variables);
        $this->assertArrayHasKey('css_classes', $variables);
        
        $this->assertEquals('test_value', $variables['value']);
        $this->assertEquals('test_field', $variables['input_name']);
    }
}