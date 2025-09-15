module.exports = {
  extends: ['@commitlint/config-conventional'],
  rules: {
    'type-enum': [
      2,
      'always',
      [
        'feat', // New feature
        'fix', // Bug fix
        'docs', // Documentation only
        'style', // Code style changes (formatting, missing semicolons, etc)
        'refactor', // Code refactoring without feature/fix
        'perf', // Performance improvements
        'test', // Adding or correcting tests
        'build', // Build system or external dependencies
        'ci', // CI configuration files and scripts
        'chore', // Other changes that don't modify src or test files
        'revert', // Reverts a previous commit
      ],
    ],
    'subject-case': [2, 'never', ['upper-case', 'start-case']],
    'subject-empty': [2, 'never'],
    'type-empty': [2, 'never'],
  },
};
