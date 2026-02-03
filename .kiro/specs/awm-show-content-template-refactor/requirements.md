# Requirements Document

## Introduction

This specification defines the requirements for refactoring the `awm_show_content` function in `includes/functions/library.php` to use template-based HTML rendering instead of inline HTML generation. The current function is approximately 800 lines and generates HTML directly within PHP code using string concatenation, which creates maintainability, customization, and separation of concerns issues.

## Glossary

- **Template_System**: The new template-based rendering system that separates HTML presentation from PHP logic
- **Input_Type**: A specific type of form input handled by awm_show_content (e.g., text, select, checkbox, etc.)
- **Template_File**: A PHP file containing HTML markup with embedded PHP variables for rendering specific input types
- **Theme_Override**: The ability for developers to customize templates by placing custom versions in their active theme
- **Legacy_Function**: The current awm_show_content function with inline HTML generation
- **Template_Parser**: The awm_parse_template function that processes template files with variables
- **Core_Templates**: Default template files provided by the plugin
- **Custom_Templates**: Developer-created template files that override core templates

## Requirements

### Requirement 1: Template File Structure

**User Story:** As a developer, I want a clear template file structure, so that I can easily locate and customize input type templates.

#### Acceptance Criteria

1. THE Template_System SHALL organize template files in `templates/frontend/inputs/` directory
2. WHEN a new Input_Type template is created, THE Template_System SHALL follow the naming convention `{input_type}.php`
3. THE Template_System SHALL support nested directories for complex input types (e.g., `input/text.php`, `input/checkbox.php`)
4. THE Template_System SHALL maintain the existing `checkbox_multiple.php` template without modification
5. THE Template_System SHALL create template files for all Input_Types currently handled by the Legacy_Function

### Requirement 2: Template Variable Passing

**User Story:** As a developer, I want a consistent variable passing mechanism, so that I can access all necessary data within templates.

#### Acceptance Criteria

1. THE Template_Parser SHALL accept an associative array of variables for each template
2. WHEN rendering a template, THE Template_System SHALL make all passed variables available as PHP variables within the template scope
3. THE Template_System SHALL pass field configuration data, values, attributes, and metadata to templates
4. THE Template_System SHALL maintain backward compatibility with the existing `$ewp_input_vars` global variable approach
5. THE Template_System SHALL sanitize and escape variables appropriately before passing to templates

### Requirement 3: Theme Override Capability

**User Story:** As a theme developer, I want to override plugin templates, so that I can customize the HTML output without modifying core plugin files.

#### Acceptance Criteria

1. THE Template_System SHALL check for template files in the active theme's directory before using Core_Templates
2. WHEN a Custom_Template exists in `{theme_directory}/extend-wp/templates/frontend/inputs/`, THE Template_System SHALL use it instead of the Core_Template
3. THE Template_System SHALL fall back to Core_Templates when Custom_Templates are not found
4. THE Template_System SHALL support child theme overrides with proper inheritance hierarchy
5. THE Template_System SHALL provide a filter hook for developers to modify template file paths

### Requirement 4: Backward Compatibility

**User Story:** As a plugin user, I want the refactoring to be seamless, so that my existing functionality continues to work without changes.

#### Acceptance Criteria

1. THE Template_System SHALL maintain the exact same HTML output as the Legacy_Function for all Input_Types
2. WHEN the refactored function is called, THE Template_System SHALL accept the same parameters as the Legacy_Function
3. THE Template_System SHALL preserve all existing filter hooks and their functionality
4. THE Template_System SHALL maintain compatibility with all view types (post, term, user, widget, etc.)
5. THE Template_System SHALL handle all existing field attributes and configurations without breaking changes

### Requirement 5: Template Loading and Caching

**User Story:** As a system administrator, I want efficient template loading, so that the refactoring doesn't negatively impact site performance.

#### Acceptance Criteria

1. THE Template_System SHALL load template files only when needed (lazy loading)
2. THE Template_System SHALL cache template file paths to avoid repeated filesystem checks
3. WHEN a template file is not found, THE Template_System SHALL log an appropriate error and fall back gracefully
4. THE Template_System SHALL clear template caches when themes are switched
5. THE Template_System SHALL provide debug information for template loading in development environments

### Requirement 6: Input Type Coverage

**User Story:** As a developer, I want templates for all supported input types, so that the entire function is properly refactored.

#### Acceptance Criteria

1. THE Template_System SHALL create templates for all Input_Types: html, input (all subtypes), select, textarea, radio, section, awm_tab, map, repeater, image, awm_gallery, message, button, function
2. WHEN processing input subtypes, THE Template_System SHALL use specific templates (e.g., `input/text.php`, `input/checkbox.php`, `input/number.php`)
3. THE Template_System SHALL handle complex Input_Types like repeater and awm_tab with nested template rendering
4. THE Template_System SHALL maintain special handling for hidden inputs and meta field generation
5. THE Template_System SHALL preserve all existing input type behaviors and validations

### Requirement 7: Error Handling and Debugging

**User Story:** As a developer, I want clear error handling, so that I can troubleshoot template issues effectively.

#### Acceptance Criteria

1. WHEN a template file is missing, THE Template_System SHALL log a descriptive error message
2. THE Template_System SHALL provide fallback rendering using inline HTML generation for missing templates
3. WHEN template parsing fails, THE Template_System SHALL display helpful debugging information in development mode
4. THE Template_System SHALL validate template variables before passing them to templates
5. THE Template_System SHALL provide hooks for developers to customize error handling behavior

### Requirement 8: Template Documentation and Examples

**User Story:** As a developer, I want clear documentation and examples, so that I can create custom templates effectively.

#### Acceptance Criteria

1. THE Template_System SHALL provide inline documentation in all Core_Templates showing available variables
2. THE Template_System SHALL include example Custom_Templates demonstrating common customization patterns
3. THE Template_System SHALL document the template hierarchy and override system
4. THE Template_System SHALL provide migration guides for developers with existing customizations
5. THE Template_System SHALL include PHPDoc comments explaining template variable structures

### Requirement 9: Template Validation and Security

**User Story:** As a security-conscious developer, I want secure template handling, so that custom templates cannot introduce vulnerabilities.

#### Acceptance Criteria

1. THE Template_System SHALL validate that template files contain only PHP and HTML code
2. THE Template_System SHALL escape output variables by default to prevent XSS attacks
3. WHEN processing user-provided template variables, THE Template_System SHALL sanitize input appropriately
4. THE Template_System SHALL restrict template file locations to prevent directory traversal attacks
5. THE Template_System SHALL provide secure defaults while allowing developers to override when necessary

### Requirement 10: Gutenberg Block Compatibility

**User Story:** As a developer using Gutenberg blocks, I want the template system to work seamlessly with the existing block infrastructure, so that I can use the same field definitions for both traditional forms and Gutenberg blocks.

#### Acceptance Criteria

1. THE Template_System SHALL maintain compatibility with the existing EWP_Dynamic_Blocks class and its field processing
2. WHEN a field is used in both traditional forms and Gutenberg blocks, THE Template_System SHALL render consistently across both contexts
3. THE Template_System SHALL support all render_type values used by Gutenberg blocks (color, textarea, boolean, string, number, select, gallery)
4. THE Template_System SHALL preserve the awm_prepare_field function's field transformation logic for block compatibility
5. THE Template_System SHALL ensure that template variables are compatible with both server-side rendering and REST API preview endpoints

### Requirement 11: Migration and Testing Support

**User Story:** As a quality assurance engineer, I want comprehensive testing support, so that I can verify the refactoring maintains functionality.

#### Acceptance Criteria

1. THE Template_System SHALL provide a comparison mode that validates new template output against Legacy_Function output
2. THE Template_System SHALL include automated tests for all Input_Types and their template rendering
3. THE Template_System SHALL support A/B testing between template-based and legacy rendering
4. THE Template_System SHALL provide migration utilities for existing customizations
5. THE Template_System SHALL include performance benchmarks comparing old and new implementations