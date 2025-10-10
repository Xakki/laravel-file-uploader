SHELL = /bin/bash
### https://makefiletutorial.com/

-include ./.env
export

docker := docker run -it --rm -v $(PWD):/app -w /app xakki/php:8.3-fpm
composer := $(docker) composer

bash:
	$(docker) bash

composer-i:
	$(composer) i --prefer-dist --no-scripts

composer-u:
	$(composer) u --prefer-dist $(name)

composer-r:
	$(composer) r --prefer-dist $(name)

cs-fix:
	$(composer) cs-fix

cs-check:
	$(composer) cs-check

phpstan:
	$(composer) phpstan

phpunit:
	$(composer) phpunit

test:
	$(composer) cs-check
	$(composer) phpstan
	$(composer) phpunit
