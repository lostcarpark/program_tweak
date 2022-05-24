<?php

/**
 * Check if parameter is a flag (i.e., if it starts with a '-').
 *
 * @param string $value
 * @return bool
 */
function checkParamIsFlag($value)
{
  if (substr($value, 0, 1) == '-') {
    return true;
  }
  return false;
}

/**
 * Parse parameters passed to the script into object.
 *
 * @param int $argc
 * @param array @argv
 * @return mixed
 */
function extractParameters($argc, $argv)
{
  $parameters = (object) [
    'start' => null,
    'input' => [],
    'output' => [],
    'joindate' => false,
    'splitdate' => false,
    'numeric' => false,
    'guid' => false,
    'json' => false,
    'timezone' => false,
  ];
  for ($param = 1; $param < $argc; $param++) {
    switch ($argv[$param]) {
      case '-s':
      case '--start':
        if (checkParamIsFlag($argv[$param + 1]))
          return null;
        $parameters->start = $argv[++$param];
        break;
      case '-i':
      case '--input':
        if (checkParamIsFlag($argv[$param + 1])) {
          echo "No input file specified.\n";
          return null;
        }
        $parameters->input[0] = $argv[++$param];
        // Check if optional second input filename.
        if (!checkParamIsFlag($argv[$param + 1]))
          $parameters->input[1] = $argv[++$param];
        break;
      case '-o':
      case '--output':
        if (checkParamIsFlag($argv[$param + 1])) {
          echo "No output file specified.\n";
          return null;
        }
        $parameters->output[0] = $argv[++$param];
        // Check if optional second input filename.
        if (!checkParamIsFlag($argv[$param + 1]))
          $parameters->output[1] = $argv[++$param];
        break;
      case '-j':
      case '--joindate':
        $parameters->joindate = true;
        break;
      case '-S':
      case '--splitdate':
        $parameters->splitdate = true;
        break;
      case '-n':
      case '--numeric':
        $parameters->numeric = true;
        break;
      case '-g':
      case '--guid':
        $parameters->guid = true;
        break;
      case '-J':
      case '--json':
        $parameters->json = true;
        break;
      case '-t':
      case '--timezone':
        $parameters->timezone = true;
        break;
      default:
        echo "Unknown parameter: " . $argv[$param] . "\n";
        return null;
    }
  }
  return $parameters;
}

/**
 * Parse string and return array of JSON objects.
 *
 * @param string $data
 * @return array
 */
function parseJson($data)
{
  $chars = mb_str_split($data, 1, 'UTF-8');
  $inEntity = false;
  $arrayLevel = 0;
  $objectLevel = 0;
  $inLineComment = false;
  $inBlockComment = false;
  $inSingleQuote = false;
  $inDoubleQuote = false;
  $jsonEntities = [];
  $prevChar = null;
  $json = "";

  $countChars = count($chars);
  for ($curPos = 0; $curPos < $countChars; $curPos++) {
    $curChar = $chars[$curPos];
    if ($prevChar != "\\") {
      switch ($curChar) {
        case "/":
          $nextChar = $chars[$curPos + 1];
          if (
            $nextChar == "/" &&
            !$inBlockComment &&
            !$inSingleQuote &&
            !$inDoubleQuote
          ) {
            $inLineComment = true;
          }
          if (
            $nextChar == "*" &&
            !$inLineComment &&
            !$inSingleQuote &&
            !$inDoubleQuote
          ) {
            $inBlockComment = true;
          }
          break;
        case "\n":
        case "\r":
          $inLineComment = false;
          break;
        case "'":
          if ($inSingleQuote) {
            // In string, so closing quote.
            $inSingleQuote = false;
            break;
          }
          if (!$inDoubleQuote)
            $inSingleQuote = true; // Opening quote.
          break;
        case '"':
          if ($inDoubleQuote) {
            // In string already, so closing quote.
            $inDoubleQuote = false;
            break;
          }
          if (!$inSingleQuote)
            $inDoubleQuote = true; // Opening quote.
          break;
        case "{":
          if (!$inSingleQuote && !$inDoubleQuote) {
            // Make sure not in string.
            if (!$inEntity) {
              // If start of entity, note position.
              $inEntity = true;
            }
            $objectLevel++; // Increase object level to allow for nested objects.
          }
          break;
        case "}":
          if (!$inSingleQuote && !$inDoubleQuote) {
            $objectLevel--;
          }
          break;
        case "[":
          if (!$inSingleQuote && !$inDoubleQuote) {
            // Make sure not in string.
            if (!$inEntity) {
              // If start of entity, note position.
              $inEntity = true;
            }
            $arrayLevel++; // Increase array level to allow for nested arrays.
          }
          break;
        case "]":
          if (!$inSingleQuote && !$inDoubleQuote) {
            $arrayLevel--; // Decrease array level.
          }
          break;
        default:
          break;
      }
    }
    // If we're inside an entity, add character (unless inside comment), then check if end of entity.
    if ($inEntity && !$inLineComment && !$inBlockComment) {
      $json .= $curChar;
      if ($objectLevel === 0 && $arrayLevel === 0) {
        // No longer in entity, so push to entities array, and reset json string.
        $jsonEntities[] = json_decode($json);
        $json = "";
        $inEntity = false;
      }
    }
    // We need to process end comment after add char to json, so the closing "/" doesn't get added.
    if (
      $prevChar == "*" &&
      $curChar == "/" &&
      $inBlockComment &&
      !$inSingleQuote &&
      !$inDoubleQuote
    ) {
      $inBlockComment = false;
    }
    $prevChar = $curChar;
  }

  return $jsonEntities;
}

/**
 * Fild the oldest date in the program array.
 *
 * @param array $program
 * @return string
 */
function findOldestDate($program)
{
  $oldest = "9999-99-99"; // Set to an impossibly far future date.
  foreach ($program as $item) {
    if (isset($item->date) && $item->date < $oldest) {
      $oldest = $item->date;
    }
    if (isset($item->datetime) && $item->datetime < $oldest) {
      $oldest = substr($item->datetime, 0, 10);
    }
  }
  return $oldest;
}

/**
 * Update dates by amount of offset.
 *
 * @param array $program
 * @param int $offset
 * @param bool $join
 * @param bool $split
 * @return array
 */
function addOffsetToDates($program, $offset, $join, $split, $timezone)
{
  foreach ($program as $item) {
    if (isset($item->datetime)) {
      if (strlen($item->datetime) > 19) {
        $oldTime = strtotime($item->datetime);
      }
      else {
        $oldTime = strtotime($item->datetime . '+00:00');
      }
      $isDateTime = true;
      unset($item->datetime);
    } else {
      $oldTime = strtotime($item->date . 'T' . $item->time . '+00:00');
      $isDateTime = false;
      unset($item->date);
      unset($item->time);
    }
    $newTime = $oldTime + $offset;
    if ($join || (!$split && $isDateTime)) {
      if ($timezone) {
        $item->datetime = gmdate("c", $newTime);
      } else {
        $item->datetime = substr(gmdate("c", $newTime), 0, 19);
      }
    }
    if ($split || (!$join && !$isDateTime)) {
      $item->date = gmdate("Y-m-d", $newTime);
      $item->time = gmdate("H:i:s", $newTime);
    }
  }
  return $program;
}

/**
 * Returns a GUIDv4 string
 *
 * Uses the best cryptographically secure method
 * for all supported pltforms with fallback to an older,
 * less secure version.
 *
 * @param bool $trim
 * @return string
 */
function GUIDv4($trim = true)
{
  // Windows
  if (function_exists('com_create_guid') === true) {
    if ($trim === true)
      return trim(com_create_guid(), '{}');
    else
      return com_create_guid();
  }

  // OSX/Linux
  if (function_exists('openssl_random_pseudo_bytes') === true) {
    $data = openssl_random_pseudo_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);    // set version to 0100
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);    // set bits 6-7 to 10
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
  }

  // Fallback (PHP 4.2+)
  mt_srand((float)microtime() * 10000);
  $charid = strtolower(md5(uniqid(rand(), true)));
  $hyphen = chr(45);                  // "-"
  $lbrace = $trim ? "" : chr(123);    // "{"
  $rbrace = $trim ? "" : chr(125);    // "}"
  $guidv4 = $lbrace .
    substr($charid,  0,  8) . $hyphen .
    substr($charid,  8,  4) . $hyphen .
    substr($charid, 12,  4) . $hyphen .
    substr($charid, 16,  4) . $hyphen .
    substr($charid, 20, 12) .
    $rbrace;
  return $guidv4;
}

/**
 * Replace IDs in program and people.
 *
 * Replace all IDs. Convert to numeric or guids.
 *
 * @param array $program
 * @param array $people
 * @param bool $useGuids
 */
function replaceIDs($program, $people, $useGuids)
{
  $programMap = [];
  $peopleMap = [];
  $lastID = 0;

  foreach ($people as $person) {
    $newID = $useGuids ? GUIDv4() : ++$lastID;
    $peopleMap[$person->id] = $newID;
    $person->id = $newID;
  }
  foreach ($program as $item) {
    $newID = $useGuids ? GUIDv4() : ++$lastID;
    $programMap[$item->id] = $newID;
    $item->id = $newID;
    if (isset($item->people)) {
      foreach ($item->people as $itemPerson) {
        $oldID = $itemPerson->id;
        $itemPerson->id = $peopleMap[$oldID];
      }
    }
  }
}

/**
 * Write an object as JSON.
 *
 * @param resource $file
 * @param object $item
 * @param string $name
 * @param bool $plainJson
 */
function writeJson($file, $item, $name, $plainJson)
{
  if ($plainJson) {
    $json = json_encode($item) . "\n";
  } else {
    $json = "var $name = " . json_encode($item) . ";\n";
  }
  fwrite($file, $json);
}



echo "Update dates in KonOpas/ConClár JSON file.\n";

$parameters = extractParameters($argc, $argv);

// Make sure required parameters included.
if (isset($parameters) && is_null($parameters->start)) {
  echo "You must specify new start date with -s or --start.\n";
  $parameters = null;
}
if (isset($parameters) && count($parameters->input) < 1) {
  echo "You must specify input file(s) with -i or --input.\n";
  $parameters = null;
}
if (isset($parameters) && count($parameters->output) < 1) {
  echo "You must specify output file(s) with -o or --output.\n";
  $parameters = null;
}

// Make sure incompatible parameters not set.
if (isset($parameters) && $parameters->joindate && $parameters->splitdate) {
  echo "You may not select both --joindate and --splitdate.\n";
  $parameters = null;
}
if (isset($parameters) && $parameters->numeric && $parameters->guid) {
  echo "You may not select both --numeric and --guid.\n";
  $parameters = null;
}

// Make sure parameters valid.
if (is_null($parameters)) {
  echo "Call as follows:\n";
  echo "php program_tweak.php --start <YYYY-MM-DD> --input <source_files> --output <dest_files> <options>\n";
  echo "Required parameters:\n";
  echo "  --start or -s  The new start date\n";
  echo "  --input or -i <combined program and people.json>   OR\n";
  echo "  --input or -i <program.json> <people.json>\n";
  echo "  --output or -o <combined program and people output.json>  OR\n";
  echo "  --output or -o <program output.json> <people output.json>\n";
  echo "Optional flags:\n";
  echo "  --joindate or -j  Join date and time fields\n";
  echo "  --splitdate or -S  Separate date and time fields\n";
  echo "  --numeric or -n  Replace keys with numeric values\n";
  echo "  --guid or -g  Replace keys with GUIDs\n";
  echo "  --json or -J  Output in plain JSON without `var` statements.\n";
  echo "  --timezone or -t  Include timezone in joined datetime output.\n";
  exit(0);
}


// Convert new start date to timestamp.
$newStartDate = strtotime($parameters->start);

// Open the input file and get the oldest date.
if (count($parameters->input) == 1) {
  $inputData = file_get_contents($parameters->input[0]);
  list($program, $people) = parseJson($inputData);
} else {
  $inputData = file_get_contents($parameters->input[0]);
  $program = $inputData[0];
  $inputData = file_get_contents($parameters->input[1]);
  $people = $inputData[0];
}
$oldest = strtotime(findOldestDate($program));
$offset = $newStartDate - $oldest;

$program = addOffsetToDates($program, $offset, $parameters->joindate, $parameters->splitdate, $parameters->timezone);
if ($parameters->guid || $parameters->numeric) {
  replaceIDs($program, $people, $parameters->guid);
}

$file = fopen($parameters->output[0], "w");
writeJson($file, $program, 'program', $parameters->json);

if (count($parameters->output) == 1) {
  writeJson($file, $people, 'people', $parameters->json);
}
fclose($file);

if (count($parameters->output) > 1) {
  $file = fopen($parameters->output[1], "w");
  writeJson($file, $people, 'people', $parameters->json);
  fclose($file);
}
