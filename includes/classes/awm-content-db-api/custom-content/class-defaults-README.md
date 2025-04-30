# Extend WP Default Content Class

## Overview
This file contains the `Extend_WP_Default_Content` class which is responsible for registering default content objects for the Extend WP plugin. It provides essential functionality for managing WordPress dashboard widgets, configuring admin menus, and defining the plugin's administration interface settings.

## Class Purpose
The `Extend_WP_Default_Content` class serves as a central configuration point for the Extend WP plugin, handling:

1. **Dashboard Widget Management**: Controls which default WordPress dashboard widgets are displayed to non-administrator users.
2. **Admin Menu Configuration**: Sets up the Extend WP menu in the WordPress admin interface with proper user role permissions.
3. **Settings Fields Definition**: Defines all the settings fields and sections for the plugin's administration interface.

## Key Features

### Dashboard Widget Control
- Selectively removes default WordPress dashboard widgets for non-administrator users
- Configurable through plugin settings to allow dashboard widgets for specific user roles

### User Role Management
- Restricts plugin access to specific user roles
- Always allows administrator access
- Configurable through the plugin settings

### Configuration Management
The class defines several configuration sections:

1. **General Settings**
   - User role access configuration
   - Dashboard widget permissions

2. **Export Settings**
   - Auto-export toggle
   - Content type selection for export
   - Export file path configuration

3. **Import Settings**
   - Import toggle
   - Import method selection
   - Import file path configuration

4. **Developer Settings**
   - Google Maps API key configuration

## Usage
The class is automatically instantiated when the plugin loads:

```php
new Extend_WP_Default_Content();
```

No additional configuration is required to use this class as it hooks into WordPress automatically through its constructor.

## Integration
This class is part of the larger Extend WP plugin ecosystem and works in conjunction with the custom content type system described in the main README.md file. It provides the administrative interface and configuration options needed to manage the custom content types.
