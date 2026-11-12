const aiTools = [
  'aider',
  'amp',
  'anthropic',
  'chatgpt',
  'claude',
  'codex',
  'copilot',
  'cursor',
  'devin',
  'gemini',
  'jules',
  'openai',
  'opencode',
  'qwen',
  'windsurf',
].join('|');

// Unicode-aware word boundaries, so "Ampère" or "OpenCoder" don't match.
const tool = `(?<![\\p{L}\\p{N}])(?:${aiTools})(?![\\p{L}\\p{N}])`;

const forbiddenPatterns = [
  // Any co-author, human or not.
  /^Co-authored-by:.*/im,
  // Attribution trailers naming a tool.
  new RegExp(
    `^(?:Assisted-by|Generated-by|Made-with|Signed-off-by):.*${tool}`,
    'imu',
  ),
  // Tool-specific session and thread trailers.
  new RegExp(`^${tool}-[\\w-]+:`, 'imu'),
  // Prose attribution naming a tool.
  new RegExp(`(?:Generated|Made) (?:with|by|using)\\b.*${tool}`, 'iu'),
];

// Sift ticket IDs point at a private tracker. Built from parts so this file
// doesn't contain one.
const ticketId = new RegExp(['SO21', '-', '\\d{4}'].join(''));

module.exports = {
  extends: ['@commitlint/config-conventional'],
  plugins: [
    {
      rules: {
        'no-ai-generated': parsed => {
          const message = parsed.raw;

          const match = forbiddenPatterns.reduce(
            (firstMatch, pattern) => firstMatch || message.match(pattern),
            null,
          );
          if (match) {
            return [
              false,
              `Commit message contains forbidden AI-generated content: "${match[0]}"`,
            ];
          }

          return [true, ''];
        },
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
    'no-ai-generated': [2, 'always'],
    'no-sift-ticket-ids': [2, 'always'],
  },
};
