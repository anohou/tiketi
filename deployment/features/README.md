# Feature System Documentation

## Overview

This directory contains pluggable deployment features. Each feature is self-contained and can be enabled/disabled via `deployment.config.yml`.

## Architecture

- **One Feature = One File** - Easy to understand and maintain
- **Configuration-Driven** - Toggle features in YAML
- **Hook-Based** - Features register for deployment lifecycle events
- **No Coupling** - Features don't depend on each other

## Directory Structure

```
features/
├── loader.sh              # Feature orchestrator
├── validation/            # Input/config validation
├── security/              # Security features
├── reliability/           # Rollback, retry, etc.
├── monitoring/            # Logging, drift detection
├── notifications/         # Discord, Slack, Telegram
└── advanced/              # Zero-downtime, etc.
```

## Feature Template

Every feature implements:
- `is_enabled()` - Check if enabled in config
- `init()` - Initialize (runs once)
- `validate()` - Validate configuration
- `hook_*()` - Hook functions for lifecycle events
- `cleanup()` - Cleanup on exit

## Deployment Hooks

Features can register for these hooks:

1. `pre-validation` - Before validation
2. `post-validation` - After validation passes
3. `pre-build` - Before building/pulling image
4. `post-build` - After image ready
5. `pre-deploy` - Before starting container
6. `post-deploy` - After container started
7. `pre-health` - Before health checks
8. `post-health` - After health passes
9. `on-failure` - When deployment fails
10. `on-success` - When deployment succeeds

## Configuration

Enable/disable in `deployment.config.yml`:

```yaml
features:
  input-validation:
    enabled: true
    # feature-specific config

  discord:
    enabled: false  # Easily disable
```

## Adding New Features

1. Create file in appropriate category directory
2. Implement standard interface
3. Add hooks as needed
4. Update `loader.sh` to include file
5. Add configuration to `deployment.config.yml`

## Testing

Test features independently:
```bash
# Source the feature
source deployment/features/validation/input-validation.sh

# Test functions
is_enabled && echo "Enabled"
init
validate
```
