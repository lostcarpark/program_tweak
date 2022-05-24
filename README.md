# Program Tweak

This is a PHP CLI script for rewriting JSON program/people files as used by ConClár/KonOpas.

This allows a wide array of file variants 

Call as follows:

`php program_tweak.php --start <YYYY-MM-DD> --input <source_files> --output <dest_files> <options>`

## Required parameters

  - `--start` or `-s`  The new start date
  - `--input` or `-i` `<combined program and people.json>`   OR
  - `--input` or `-i` `<program.json> <people.json>`
  - `--output` or `-o` `<combined program and people output.json>`  OR
  - `--output` or `-o` `<program output.json> <people output.json>`

## Optional flags

  - `--joindate` or `-j`  Join date and time fields
  - `--splitdate` or `-S`  Separate date and time fields
  - `--numeric` or `-n`  Replace keys with numeric values
  - `--guid` or `-g`  Replace keys with GUIDs
  - `--json` or `-J`  Output in plain JSON without `var` statements.
  - `--timezone` or `-t`  Include timezone in joined datetime output.
