<?php

// Stands in for `sh4objtest worker`: passes every work item it's sent.
while (($line = fgets(STDIN)) !== false) {
    $message = json_decode($line, true);
    if ($message['type'] === 'shutdown') {
        break;
    }

    fwrite(STDOUT, json_encode([
        'type' => 'file_result',
        'testFile' => $message['testFile'],
        'objectFile' => $message['objectFile'],
        'success' => true,
        'tests' => [['name' => $message['testFile'], 'status' => 'pass', 'message' => '']],
        'coverage' => null,
    ]) . "\n");
    fflush(STDOUT);
}
