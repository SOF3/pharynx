# pharynx

A tool to recompile PHP sources into a phar in PSR-0

```txt
USAGE
  -v           : Enable verbose output.
  -i PATH      : Equivalent to `-i plugin.yml:PATH/plugin.yml -i PATH/resources -s PATH/src`.
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
