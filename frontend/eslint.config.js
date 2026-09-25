import js from '@eslint/js'
import pluginVue from 'eslint-plugin-vue'
import prettier from 'eslint-config-prettier'
import globals from 'globals'

export default [
  { ignores: ['dist/**', 'node_modules/**'] },
  js.configs.recommended,
  ...pluginVue.configs['flat/recommended'],
  prettier,
  { files: ['*.config.js'], languageOptions: { globals: globals.node } },
  {
    languageOptions: { globals: globals.browser },
    rules: {
      // Einwortige Seitennamen wie "Login" sind hier bewusst erlaubt.
      'vue/multi-word-component-names': 'off',
    },
  },
]
