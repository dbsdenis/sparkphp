<?php

declare(strict_types=1);

function sparkRealtimeServe(array $args): void
{
    require_once SPARK_BASE . '/core/Realtime.php';

    realtime()->serveWebSocket($args);
}
