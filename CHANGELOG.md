# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Added framework-agnostic subscription billing core (`Plan`, `Subscription`, `SubscriptionService`, `Proration`, `Invoice`, `Cli`, `FrozenClock`, `FakeGateway`, and `SequentialNumberer`) with exact integer proration and calendar-anchored periods.
- Initial project structure from the factory utility template.
