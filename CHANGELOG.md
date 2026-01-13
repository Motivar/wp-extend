# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Default Value Support for Input Fields**: Added ability to set default values for all input field types using the `default` key in field definitions.
  - **Original Question**: "Is it possible for the simple inputs to set default value if value is not set?"
  - **Solution**: Enhanced `awm_show_content()` function to check for `default` key and apply it when field value is empty (preserves zero values)
  - **Affected Files**: `/includes/functions/library.php`
  - **Usage**: Add `'default' => 'value'` to any field definition (input, select, textarea, radio, etc.)
  - **Backwards Compatibility**: Fully backwards compatible - only applies when `default` key is explicitly set

### Fixed
- **Database Schema Updates**: Fixed issue where column data type changes (e.g., LONGTEXT to VARCHAR) were not being applied during table version updates. The AWM_DB_Creator class now properly detects and modifies existing columns when their definitions change, not just adds missing columns.
  - **Original Issue**: Column alterations for `cookie_id` (LONGTEXT → VARCHAR(32)) and `address` (LONGTEXT → VARCHAR(45)) in `flx_session_users` table were not being applied
  - **Solution**: Enhanced `dbUpdate()` method to compare existing column definitions with new ones and execute `ALTER TABLE MODIFY COLUMN` statements when changes are detected
  - **Affected Files**: `/includes/classes/awm-db/class-db-creator.php`
  - **Backwards Compatibility**: Fully backwards compatible - existing functionality preserved while adding column modification support

## [1.0.0] - 2024-11-14

### Added
- Initial release of the WordPress Extend plugin
- Database creation and management utilities
- Custom list management functionality
- Form handling and validation
- Admin interface enhancements
