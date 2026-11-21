import js from '@eslint/js';
import globals from 'globals';

export default [
  { ignores: ['vendor/**', 'node_modules/**', 'test-results/**', 'playwright-report/**', 'build/**'] },
  js.configs.recommended,
  {
    files: ['resources/js/**/*.js'],
    languageOptions: { ecmaVersion: 2022, sourceType: 'module', globals: { ...globals.browser } },
  },
  {
    // Classic worker scripts: config and imports arrive through self.__PWA / importScripts.
    files: ['resources/sw/**/*.js'],
    languageOptions: { ecmaVersion: 2022, sourceType: 'script', globals: { ...globals.serviceworker } },
  },
  {
    files: ['**/*.mjs'],
    languageOptions: { ecmaVersion: 2023, sourceType: 'module', globals: { ...globals.node } },
  },
  {
    files: ['tests/e2e/**/*.mjs'],
    languageOptions: { globals: { ...globals.node, ...globals.browser } },
  },
  {
    rules: {
      'no-unused-vars': ['error', { argsIgnorePattern: '^_', caughtErrors: 'none' }],
      eqeqeq: ['error', 'always'],
      'no-var': 'error',
      'prefer-const': 'error',
    },
  },
];
