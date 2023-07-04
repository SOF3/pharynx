#!/usr/bin/env bash

# Modified from https://github.com/pmmp/PocketMine-MP/blob/stable/start.sh

# Change this to the absolute path to your plugin
PLUGIN_PATH="dev"

compile_plugin() {
	export PHP_BINARY PLUGIN_PATH PHARYNX_PATH
	local COMPOSER=""
	if [[ -f $PLUGIN_PATH/composer.json ]]; then
		COMPOSER=="-c"
	fi
	"$PHP_BINARY" -dphar.readonly=0 "$PHARYNX_PATH" $COMPOSER -i "$PLUGIN_PATH" -p=plugins/pharynx-output.phar
 
	if [[ ! -f $PLUGIN_PATH/composer.json ]]; then
		"$PHP_BINARY" -dphar.readonly=0 "$DIR"/bootstrap-plugin-dev.php plugins/pharynx-output.phar
	fi
}

DIR="$(cd -P "$( dirname "${BASH_SOURCE[0]}" )" && pwd)"
cd "$DIR"

PHARYNX_PATH="$DIR/pharynx.phar"

while getopts "p:f:i:l" OPTION 2> /dev/null; do
	case ${OPTION} in
		p)
			PHP_BINARY="$OPTARG"
			;;
		f)
			POCKETMINE_FILE="$OPTARG"
			;;
		i)
			PLUGIN_PATH="$OPTARG"
			;;
		l)
			DO_LOOP="yes"
			;;
		\?)
			break
			;;
	esac
done

if [ "$PHP_BINARY" == "" ]; then
	if [ -f ./bin/php7/bin/php ]; then
		export PHPRC=""
		PHP_BINARY="./bin/php7/bin/php"
	elif [[ ! -z $(type php 2> /dev/null) ]]; then
		PHP_BINARY=$(type -p php)
	else
		echo "Couldn't find a PHP binary in system PATH or $PWD/bin/php7/bin"
		echo "Please refer to the installation instructions at https://doc.pmmp.io/en/rtfd/installation.html"
		exit 1
	fi
fi

if [ "$POCKETMINE_FILE" == "" ]; then
	if [ -f ./PocketMine-MP.phar ]; then
		POCKETMINE_FILE="./PocketMine-MP.phar"
	else
		echo "PocketMine-MP.phar not found"
		echo "Downloads can be found at https://github.com/pmmp/PocketMine-MP/releases"
		exit 1
	fi
fi

LOOPS=0

set +e

if [ "$DO_LOOP" == "yes" ]; then
	set -e
	compile_plugin
	set +e

	while true; do
		if [ ${LOOPS} -gt 0 ]; then
			echo "Restarted $LOOPS times"
		fi
		"$PHP_BINARY" "$POCKETMINE_FILE" $@
		echo "To escape the loop, press CTRL+C now. Otherwise, wait 5 seconds for the server to restart."
		echo ""
		sleep 5
		((LOOPS++))
	done
else
	set -e
	compile_plugin
	set +e

	exec "$PHP_BINARY" "$POCKETMINE_FILE" $@
fi

