# Elastica Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## 4.0.0 - 2024-11-19
### Added
- Added support for ElasticSearch 8
- Added a config setting for a custom ttr for the reindex queue job
- Drop support for ElasticSearch 7 (use 3.x or 2.x instead)

## 3.0.0 - 2024-11-15
### Added
- Added support for Craft 5
- Drop support for Craft 4 (use 2.x instead)

## 2.0.0 - 2024-10-02
### Added
- Added support for Craft 4
- Added optional indexing of categories and assets
- Added a connection status indicator to the utility
- Added an option to define search templates

### Changed
- Include site handle in index name

## 1.0.2.2 - 2022-02-04
### Fixed
- Fix indexing after restoring trashed entries

## 1.0.2.1 - 2021-01-20
### Fixed
- Fixed index deletion on entry disable/delete

## 1.0.2 - 2021-01-06
### Added
- Logo

### Fixed
- Deletion of inactive (multisite) elements
- Composer namespace

### Changed
- Use section handle for index name

## 1.0.1 - 2021-01-05
### Added
- Initial release
