<?php
require "vendor/autoload.php";
use OCA\OcrFlow\Service\RetryPolicy;
$p = new RetryPolicy();
foreach (['error_worker_unclassified','error_provider_permission_denied','error_queued_twice_for_same_bytes','error_worker_request_malformed','error_file_broken'] as $c) {
  printf("%-38s verdict=%s retryable=%s known=%s\n", $c, $p->verdict($c), var_export($p->isRetryable($c), true), var_export($p->isKnown($c), true));
}