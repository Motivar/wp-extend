# Implementation Plan: AWM Show Content Template Refactor

## Overview

This implementation plan converts the design for refactoring the `awm_show_content` function into discrete coding tasks. The approach focuses on incremental development with early validation through testing, maintaining backward compatibility throughout the process.

## Tasks

- [x] 1. Set up template system foundation and core interfaces
  - Create directory structure for template system classes
  - Define core interfaces and abstract classes for template components
  - Set up autoloading for new template system classes
  - _Requirements: 1.1, 2.1_

- [ ] 2. Implement template locator and hierarchy system
  - [ ] 2.1 Create AWM_Template_Locator class with hierarchy resolution
    - Implement template search logic following WordPress plugin best practices
    - Support child theme, parent theme, and core plugin template locations
    - Add template path caching with WordPress transients
    - _Requirements: 3.1, 3.2, 3.3, 3.4_
  
  - [ ]* 2.2 Write property test for template hierarchy resolution
    - **Property 2: Template Hierarchy Resolution**
    - **Validates: Requirements 3.1, 3.2, 3.3, 3.4**
  
  - [ ] 2.3 Implement template cache management
    - Create AWM_Template_Cache class with invalidation on theme switch
    - Add development mode cache bypass functionality
    - Implement cache warming for frequently used templates
    - _Requirements: 5.2, 5.4_

- [ ] 3. Create variable sanitizer and security layer
  - [ ] 3.1 Implement AWM_Variable_Sanitizer class
    - Add HTML, attribute, URL, and JavaScript escaping methods
    - Create context-aware sanitization based on output location
    - Implement variable type validation against schema
    - _Requirements: 2.5, 9.2, 9.3_
  
  - [ ]* 3.2 Write property test for variable sanitization security
    - **Property 3: Variable Sanitization Security**
    - **Validates: Requirements 2.5, 9.2, 9.3**
  
  - [ ] 3.3 Add security validation for template paths
    - Implement directory traversal prevention
    - Restrict template loading to approved directories
    - Add security violation logging and handling
    - _Requirements: 9.4_
  
  - [ ]* 3.4 Write property test for template path security
    - **Property 10: Template Path Security**
    - **Validates: Requirements 9.4**

- [ ] 4. Checkpoint - Ensure foundation tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 5. Implement template parser and renderer
  - [ ] 5.1 Create AWM_Template_Parser class
    - Implement template file parsing with WordPress load_template()
    - Add template data injection via $wp_query->query_vars
    - Create template validation and error handling
    - _Requirements: 2.2, 7.3_
  
  - [ ] 5.2 Create AWM_Template_Variable_Preparer class
    - Implement variable preparation for all input types
    - Generate input names, IDs, and HTML attributes
    - Create label and explanation HTML rendering
    - _Requirements: 2.3, 6.4_
  
  - [ ]* 5.3 Write property test for template variable availability
    - **Property 5: Template Variable Availability**
    - **Validates: Requirements 2.1, 2.2, 2.3**

- [ ] 6. Create core template files for all input types
  - [ ] 6.1 Create basic input templates (text, number, checkbox, hidden, password, email)
    - Implement templates/frontend/inputs/input/ subdirectory structure
    - Create individual template files for each input subtype
    - Add comprehensive PHPDoc comments with available variables
    - _Requirements: 1.2, 1.3, 6.2_
  
  - [ ] 6.2 Create complex input templates (select, textarea, radio)
    - Implement select.php with options and optgroups support
    - Create textarea.php with wp_editor integration
    - Implement radio.php with option rendering
    - _Requirements: 6.1, 6.5_
  
  - [ ] 6.3 Create advanced input templates (section, awm_tab, map, repeater)
    - Implement nested template rendering for complex types
    - Create section.php with recursive field rendering
    - Implement awm_tab.php with tabbed interface support
    - Create map.php and repeater.php templates
    - _Requirements: 6.3_
  
  - [ ] 6.4 Create media and utility templates (image, awm_gallery, message, button, function, html)
    - Implement image.php with media upload integration
    - Create awm_gallery.php with sortable gallery support
    - Implement utility templates for message, button, function, and html types
    - _Requirements: 6.1_
  
  - [ ]* 6.5 Write property test for template file coverage
    - **Property 4: Template File Coverage**
    - **Validates: Requirements 1.5, 6.1**

- [ ] 7. Implement main template resolver
  - [ ] 7.1 Create AWM_Template_Resolver class as main orchestrator
    - Implement render_field() method as main entry point
    - Add template path resolution with caching
    - Create fallback mode for legacy HTML generation
    - _Requirements: 5.1, 7.2_
  
  - [ ] 7.2 Add error handling and logging system
    - Implement AWM_Template_Error_Handler class
    - Add comprehensive error logging for missing templates
    - Create graceful degradation with legacy fallback
    - Add development mode debugging features
    - _Requirements: 7.1, 7.2, 7.3, 7.5_
  
  - [ ]* 7.3 Write property test for error handling graceful degradation
    - **Property 8: Error Handling Graceful Degradation**
    - **Validates: Requirements 7.1, 7.2**

- [ ] 8. Checkpoint - Ensure template system tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 9. Refactor awm_show_content function to use template system
  - [ ] 9.1 Create template system integration layer
    - Add template resolver instantiation and configuration
    - Implement feature flag for gradual rollout
    - Create comparison mode for legacy vs template output
    - _Requirements: 4.1, 11.1_
  
  - [ ] 9.2 Refactor main awm_show_content function
    - Replace inline HTML generation with template resolver calls
    - Maintain exact same function signature and parameters
    - Preserve all existing filter hooks and their functionality
    - Ensure compatibility with all view types (post, term, user, widget)
    - _Requirements: 4.2, 4.3, 4.4_
  
  - [ ]* 9.3 Write property test for template-legacy output equivalence
    - **Property 1: Template-Legacy Output Equivalence**
    - **Validates: Requirements 4.1**
  
  - [ ]* 9.4 Write property test for backward compatibility preservation
    - **Property 6: Backward Compatibility Preservation**
    - **Validates: Requirements 4.2, 4.3, 4.4, 4.5**

- [ ] 10. Add Gutenberg block compatibility
  - [ ] 10.1 Ensure template system works with EWP_Dynamic_Blocks
    - Test template rendering in REST API preview endpoints
    - Verify compatibility with awm_prepare_field function
    - Ensure all render_type values work with templates
    - _Requirements: 10.1, 10.3, 10.4_
  
  - [ ]* 10.2 Write property test for Gutenberg block compatibility
    - **Property 9: Gutenberg Block Compatibility**
    - **Validates: Requirements 10.1, 10.2, 10.5**

- [ ] 11. Implement performance optimizations
  - [ ] 11.1 Add template path caching and optimization
    - Implement lazy loading for template files
    - Add cache warming for frequently used templates
    - Create cache invalidation on theme switch
    - _Requirements: 5.1, 5.2, 5.4_
  
  - [ ]* 11.2 Write property test for performance optimization
    - **Property 11: Performance Optimization**
    - **Validates: Requirements 5.1, 5.2**
  
  - [ ]* 11.3 Write property test for template cache invalidation
    - **Property 7: Template Cache Invalidation**
    - **Validates: Requirements 5.4**

- [ ] 12. Add developer tools and migration utilities
  - [ ] 12.1 Create template override detection and version checking
    - Implement template version comparison like WooCommerce
    - Add admin notice for outdated custom templates
    - Create template override status page
    - _Requirements: 8.1, 8.2_
  
  - [ ] 12.2 Create migration testing and comparison tools
    - Implement AWM_Template_Migration_Tester class
    - Add A/B testing functionality between legacy and template rendering
    - Create output comparison and difference reporting
    - _Requirements: 11.1, 11.3_
  
  - [ ]* 12.3 Write property test for input type specificity
    - **Property 12: Input Type Specificity**
    - **Validates: Requirements 6.2**

- [ ] 13. Create comprehensive documentation and examples
  - [ ] 13.1 Add inline documentation to all template files
    - Include PHPDoc comments with available variables in each template
    - Add version numbers to template file headers
    - Create comprehensive variable documentation
    - _Requirements: 8.1, 8.5_
  
  - [ ] 13.2 Create example custom templates for common use cases
    - Provide example theme override templates
    - Create customization pattern examples
    - Add migration guide for existing customizations
    - _Requirements: 8.2, 8.4_

- [ ] 14. Final integration testing and validation
  - [ ] 14.1 Run comprehensive integration tests
    - Test all input types with template rendering
    - Verify theme override functionality works correctly
    - Test Gutenberg block integration end-to-end
    - Validate error handling and fallback scenarios
    - _Requirements: 11.2_
  
  - [ ]* 14.2 Run performance benchmarks
    - Compare legacy vs template rendering performance
    - Measure template caching effectiveness
    - Validate memory usage and optimization
    - _Requirements: 11.5**

- [ ] 15. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation throughout development
- Property tests validate universal correctness properties
- Unit tests validate specific examples and edge cases
- The implementation maintains full backward compatibility while adding new template functionality