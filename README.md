# Wp-extend

A comprehensive WordPress development toolkit that provides developers with an easy-to-use framework for extending WordPress functionality.

## Description

Wp-extend is a powerful library designed to simplify WordPress development by providing a robust set of tools and APIs for common development tasks. Built with modern PHP practices and following object-oriented principles, it offers a clean and organized way to extend WordPress functionality.

## Features

- **Meta Field Management**
  - Easy handling of custom fields for posts, terms, and users
  - Dynamic meta box creation and registration
  - Customizable field types and layouts

- **Post Type & Taxonomy Management**
  - Simplified custom post type registration
  - Dynamic taxonomy creation and management
  - Automatic capability management

- **Media Management**
  - Gallery meta box functionality
  - Enhanced media handling capabilities
  - Custom gallery implementations

- **Admin Interface**
  - Custom admin messages system
  - Dynamic list table creation
  - Enhanced admin UI components

- **API Integration**
  - Built-in REST API support
  - WP-CLI commands integration
  - WPML (WordPress Multilingual) compatibility

- **Developer Tools**
  - Database operations helpers
  - Search filtering capabilities
  - Theme customizer integration
  - Gutenberg block support

## Technical Details

- Modern PHP architecture with PSR-4 autoloading
- Namespace-based organization (EWP namespace)
- Object-oriented design for better code organization
- Built with extensibility and reusability in mind

## Integration

The plugin provides multiple integration points:

- REST API endpoints for headless implementations
- WP-CLI commands for command-line operations
- Action/filter hooks for extending functionality
- Gutenberg block integration for modern editing
- WPML support for multilingual sites
- WordPress Abilities API integration for AI tooling and MCP clients (WordPress 6.9+)

### AI and the Abilities API

On WordPress 6.9 and newer the plugin registers its functionality with the core Abilities API, so an AI agent or MCP client can read the activity log, create and edit custom field groups, custom content, post types and taxonomies, export and import options pages, and flush the caches. Every ability enforces the same capability as the matching admin screen, destructive ones require an explicit confirmation, and every write is recorded in the activity log. On older WordPress nothing is registered and the plugin behaves exactly as before.

See [`docs/abilities.md`](docs/abilities.md) for the full inventory, schemas and filters.

## For Developers

Every REST route, `wp ewp` command and WordPress Ability the plugin exposes is generated from one resource declaration; see [docs/surfaces.md](docs/surfaces.md) before adding a feature, and [includes/kit/README.md](includes/kit/README.md) for the kit itself.

This toolkit is specifically designed for developers who need:
- A structured way to handle WordPress customizations
- Reusable components for common WordPress tasks
- Clean, maintainable code architecture
- Modern development practices in WordPress

## Requirements

- WordPress 5.0 or higher
- PHP 7.2 or higher
- Composer (for dependency management)

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This project is licensed under the GPL v2 or later.

## Author

Created by Giannopoulos Nikolaos
Website: https://motivar.io
