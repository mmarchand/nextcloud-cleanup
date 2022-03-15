<?php

// Configuration
$config_deletion_grace_period = intval(getenv('DELETION_GRACE_PERIOD') ?: 24 * 60 * 60);
$config_nextcloud_filename    = getenv('NEXTCLOUD_FILENAME_PATTERN') ?: 'urn:oid:%d';

// Load composer
require 'vendor/autoload.php';

// Helpers

/**
 * Converts a long string of bytes into a readable format e.g KB, MB, GB, TB, YB
 *
 * @param {Int} num The number of bytes.
 */
function readableBytes($bytes) {
  if ($bytes == 0) {
    return "0 B";
  }

  $i = floor(log($bytes) / log(1024));

  $sizes = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');

  return sprintf('%.02F', $bytes / pow(1024, $i)) * 1 . ' ' . $sizes[$i];
}

// Imports
use Aws\S3\S3Client;
use voku\db\DB;

// Instantiate clients
$s3 = new S3Client([
  'driver'      => 's3',
  'version'     => 'latest',
  'region'      => getenv('AWS_DEFAULT_REGION'),
  'endpoint'    => getenv('AWS_ENDPOINT'),
  'credentials' => [
    'key'    => getenv('AWS_ACCESS_KEY_ID'),
    'secret' => getenv('AWS_SECRET_ACCESS_KEY')
  ]
]);

// Get database instance
$db = DB::getInstance(getenv('DATABASE_HOST'), getenv('DATABASE_USER'), getenv('DATABASE_PASSWORD'), getenv('DATABASE_NAME'));

// Query to fetch files to delete
$query_leftover_uploads = <<<EOT
  SELECT `filecache`.`fileid`, `filecache`.`path`, `filecache`.`parent`, `storages`.`id` AS `storage`, `filecache`.`size`
  FROM `filecache`
  LEFT JOIN `storages` ON `storages`.`numeric_id` = `filecache`.`storage`
  WHERE `filecache`.`parent` IN (
    SELECT `fileid`
    FROM `filecache`
    WHERE `parent`=(SELECT fileid FROM `filecache` WHERE `path`="uploads")
    AND `storage_mtime` < UNIX_TIMESTAMP(NOW() - INTERVAL $config_deletion_grace_period SECOND)
  ) AND `storages`.`available` = 1;
EOT;

// Fetch records
$result = $db->query($query_leftover_uploads);
$leftover_uploads  = $result->fetchAll();

echo 'Found ' . count($leftover_uploads) . " left over files.\n";

$parent_objects = [];
$total_size     = 0;

// Delete each of these files
foreach ($leftover_uploads as $file) {
  $storage_filename = sprintf($config_nextcloud_filename, $file->fileid);
  $total_size += $file->size;

  echo " - Deleting $storage_filename / $file->path from storage $file->storage with size " . readableBytes($file->size) . "...\n";

  // Add parent object to array
  $parent_objects[] = $file->parent;

  $s3->deleteObject([
    'Bucket' => getenv('AWS_BUCKET'),
    'Key'    => $storage_filename,
  ]);

  // Delete from the DB
  $db->delete('filecache', ['fileid' => $file->fileid]);
}

// Delete all parent objects from the db
$parent_objects = array_unique($parent_objects);

foreach ($parent_objects as $parent_object) {
  $db->delete('filecache', ['fileid' => $parent_object]);
}

echo "Recovered " . readableBytes($total_size) . " from S3 storage.\n";
