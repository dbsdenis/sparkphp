<?php

// GET /
get(fn() => [
    'framework' => 'SparkPHP',
    'version'   => spark_version(),
    'release'   => spark_release_line(),
    'php'       => PHP_VERSION,
]);
