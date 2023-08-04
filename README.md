# pharynx

A tool to recompile PHP sources into a phar in PSR-0

```txt
USAGE
  -v           : Enable verbose output.
  -i PATH      : Equivalent to `-f plugin.yml:PATH/plugin.yml -f PATH/resources -s PATH/src`.
  -f NAME:PATH : Copy the file or directory at PATH to output/NAME.
                 `:` is not considered as a separator if immediately followed by a backslash.
                 Can be passed multiple times.
  -s PATH      : Use the directory at PATH as a source root.
                 Can be passed multiple times
  -r NAME      : The path of the source root in the output.
                 Default `src`.
  -o PATH      : Store the output in directory form at PATH.
  -p[=PATH]    : Pack the output in phar form at PATH.
                 If no value is given, uses the path in `-o` followed by `.phar`.
                 If neither -o nor -p are passed, or only `-p` is passed but without values,
                 `-p output.phar` is assumed.

EXAMPLE
  php pharynx.phar -i path/to/your/plugin -p output.phar
```

## Use with PocketMine plugins

Download start.cmd/start.sh, pharynx.phar and bootstrap-plugin-dev.php from [releases](https://github.com/SOF3/pharynx/releases)
and copy them to your PocketMine install directory.
Replace PocketMine's start.cmd/start.sh with the one you downloaded.
Edit start.sh/start.cmd and change `"plugin_source/MyPlugin"` to the plugin(s) to build with pharynx
(only start.sh supports multiple plugins).
Then just start the server as usual!
