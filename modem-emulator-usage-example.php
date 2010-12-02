<?php

    // Visit http://www.example.com/modem-emulator-usage-example.php?speed=56000&url=http%3A%2F%2Fwww.google.com to see Google at 56k

    // Try uncommenting following line if getting "failed to open stream" errors
    //ini_set('allow_url_fopen', true);

    require_once('modem-emulator.php');

    $file = $_REQUEST['url'];
    $speed = $_REQUEST['speed'];
    $emulator = new ModemEmulator($file, $speed);
    $emulator->run();

?>
