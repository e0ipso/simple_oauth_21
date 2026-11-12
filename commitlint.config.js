// Sift ticket IDs point at a private tracker. Built from parts so this file
// doesn't contain one.
const ticketId = new RegExp(['SO21', '-', '\\d{4}'].join(''));

module.exports = {
  extends: ['@commitlint/config-conventional'],
  plugins: [
    {
      rules: {
        'no-sift-ticket-ids': parsed => {
          const match = parsed.raw.match(ticketId);
          if (match) {
            return [
              false,
              `Commit message cites a sift ticket ID, which is private: "${match[0]}"`,
            ];
          }

          return [true, ''];
        },
      },
    },
  ],
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
    'no-sift-ticket-ids': [2, 'always'],
  },
};
