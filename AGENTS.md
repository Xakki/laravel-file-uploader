# AI Agent Guidelines

Before opening a pull request, run the following commands from the project root and ensure they succeed:

1. `vendor/bin/pint`
2. `vendor/bin/pint --test`
3. `vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M`
4. `vendor/bin/phpunit`

If any command fails, investigate and fix the issue before continuing.
