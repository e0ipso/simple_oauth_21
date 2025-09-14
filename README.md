# 🚀 GitHub Contrib Template

A GitHub repository template for creating Drupal contrib modules with comprehensive testing infrastructure, code quality tools, and AI-friendly development environment. Because writing boilerplate is so last century! ✨

## 🎯 Quick Start

1. 🖱️ Click "Use this template" on GitHub to create a new repository
2. 📥 Clone your new repository locally
3. 🛠️ Follow the setup instructions below to customize for your module

## Setup Instructions

After creating a repository from this template, follow these steps:

### 1. Rename Files and References

Replace all instances of `gh_contrib_template` with your actual module name:

- Rename `gh_contrib_template.info.yml` to `your_module_name.info.yml`
- Update module name references in all files
- Update namespace references in PHP files
- Update test class names and namespaces

### 2. Configure AI Assistant (Optional)

If you plan to use AI assistants for development:

- Rename `AGENTS.md` to match your preferred assistant (e.g., `CLAUDE.md`, `GEMINI.md`)
- Rename `tests/e2e/AGENTS.md` to match your preferred assistant (e.g., `CLAUDE.md`, `GEMINI.md`)
- Install the AI task manager:

```bash
npx @e0ipso/ai-task-manager init --assistants claude,gemini,opencode
```

### 3. Update Module Information

- Edit the `.info.yml` file with your module's details
- Update `composer.json` with your module's metadata
- Customize the module description and dependencies

## 🎁 Features Included

### 🧪 Testing Infrastructure

- **PHPUnit Test Suites**: Unit, Kernel, Functional, and FunctionalJavaScript tests
- **Trivial Test Examples**: Ready-to-adapt test templates for all test types
- **GitHub CI/CD**: Automated testing on pull requests and pushes
- **E2E Testing**: Playwright configuration for end-to-end testing

### 🔍 Code Quality Tools

- **PHPStan**: Static analysis configuration (`phpstan.neon`)
- **ESLint**: JavaScript linting (`.eslintrc.json`)
- **Prettier**: Code formatting (`.prettierrc.json`)
- **Pre-commit Hooks**: Automated code quality checks

### 🔧 Development Tools

- **GitHub Actions Workflows**:
  - `test.yml`: Comprehensive testing pipeline
  - `claude.yml`: AI assistant integration
  - `release.yml`: Release automation
- **Node.js Integration**: Package management and frontend tooling
- **Git Configuration**: Proper `.gitignore` files for Drupal modules

### 🤖 AI-Friendly Configuration

- **AGENTS.md**: Instructions for AI assistants working on the project
- **Structured Documentation**: Clear patterns for AI to follow
- **Task Management Integration**: Ready for AI task orchestration

## 🧪 Testing

The template includes comprehensive testing infrastructure that'll make your tests run smoother than a freshly cached Drupal site! 🏃‍♂️

### PHPUnit Tests

```bash
# Run all tests (grab a coffee ☕, this might take a moment)
vendor/bin/phpunit

# Run specific test suites (for the impatient developers)
vendor/bin/phpunit --testsuite=unit
vendor/bin/phpunit --testsuite=kernel
vendor/bin/phpunit --testsuite=functional
vendor/bin/phpunit --testsuite=functional-javascript
```

### Code Quality Checks

```bash
# Static analysis (let PHPStan judge your code so your colleagues don't have to)
vendor/bin/phpstan analyze

# Coding standards checks and fixes (because consistency is key 🔑)
composer run-script lint:check    # Check coding standards with PHPCS
composer run-script lint:fix      # Fix coding standards with PHPCBF

# JavaScript linting
npm run lint

# Code formatting
npm run format
```

### E2E Testing

```bash
# Install dependencies
npm ci
npm run e2e:install

# Run tests
npm run e2e:test
```

## 📁 Directory Structure

```
your_module_name/
├── .github/workflows/          # CI/CD pipelines
├── .claude/                   # AI assistant configuration
├── src/                       # PHP source code
├── tests/
│   ├── src/                   # PHPUnit tests
│   └── e2e/                   # Playwright E2E tests
├── config/                    # Configuration files
├── AGENTS.md                  # AI assistant instructions
├── composer.json              # PHP dependencies
├── package.json               # Node.js dependencies
├── phpstan.neon              # Static analysis config
└── your_module_name.info.yml  # Drupal module info
```

## ⚙️ GitHub Actions Integration

The template includes three main workflows:

- **Test Pipeline**: Runs on every PR and push, executing all test suites
- **AI Integration**: Supports AI-assisted development workflows
- **Release Automation**: Handles versioning and releases

## 🔄 Development Workflow

1. 🌿 Create feature branches from `main`
2. ✍️ Write tests for new functionality (TDD FTW!)
3. 💻 Implement features following Drupal coding standards
4. 🤖 Code quality checks run automatically via GitHub Actions
5. 🔀 Create pull requests for review
6. 🎉 Merge after tests pass and review approval (victory dance optional but encouraged)

## 🤖 AI Assistant Integration

This template is optimized for AI-assisted development (yes, your robot overlords appreciate good code structure too!):

- Clear file organization and naming conventions
- Comprehensive documentation and examples
- Structured test patterns for AI to follow
- Pre-configured development tools and workflows

## ✅ Compatibility

- **Drupal**: 10.x, 11.x
- **PHP**: 8.1+
- **Node.js**: 18+
- **GitHub Actions**: Latest runner versions

## 🤝 Contributing

After setting up your module (you're almost there!):

1. Update this README with module-specific information
2. Customize the GitHub templates and workflows
3. Add your module's specific documentation
4. Configure any additional development tools needed

## 📜 License

Update the license information according to your module's licensing requirements. Remember: sharing is caring! 💙
