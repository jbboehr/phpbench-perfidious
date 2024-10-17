<?php

$opts = getopt('', [
    'class:',
    'file:',
    'subject:',
    'revolutions:',
    'beforeMethods:',
    'afterMethods:',
    'warmup:',
    'bootstrap:',
]);

$class = $opts['class'] ?? throw new RuntimeException();
$file = $opts['file'] ?? throw new RuntimeException();
$subject = $opts['subject'] ?? throw new RuntimeException();

$revolutions = $opts['revolutions'] ?? throw new RuntimeException();
settype($revolutions, 'int');

$beforeMethods = $opts['beforeMethods'] ?? '';
$beforeMethods = array_filter(explode(',', $beforeMethods));

$afterMethods = $opts['afterMethods'] ?? '';
$afterMethods = array_filter(explode(',', $afterMethods));

$warmup = $opts['warmup'] ?? 0;
settype($warmup, 'int');

$bootstrap = $opts['bootstrap'] ?? null;

$body = stream_get_contents(STDIN);
$parameters = $body ? unserialize($body) : [];

// disable garbage collection
gc_collect_cycles();
gc_disable();

// repress any output from the user scripts
ob_start();

if ($bootstrap) {
    call_user_func(function () use ($bootstrap) {
        require_once($bootstrap);
    });
}

require_once($file);

$benchmark = new $class();

// run before methods
foreach ($beforeMethods as $beforeMethod) {
    $benchmark->$beforeMethod($parameters);
}

// run warmup if required
if ($warmup) {
    for ($i = 0; $i < $warmup; $i++) {
        $benchmark->{$subject}($parameters);
    }
}


// only pass parameters if they are supplied:passing parameters costs time
if ($parameters) {
    $startTime = microtime(true);

    for ($i = 0; $i < $revolutions; $i++) {
        $benchmark->{$subject}($parameters);
    }
    $endTime = microtime(true);
} else {
    $startTime = microtime(true);

    for ($i = 0; $i < $revolutions; $i++) {
        $benchmark->{$subject}();
    }
    $endTime = microtime(true);
}


$time = ($endTime - $startTime) * 1000000;

// run after methods
foreach ($afterMethods as $afterMethod) {
    $benchmark->$afterMethod($parameters);
}

$buffer = ob_get_contents();
ob_end_clean();

echo serialize([
    'mem' => [
        // observer effect - getting memory usage affects memory usage. order
        // counts, peak is probably the best metric.
        'peak' => memory_get_peak_usage(),
        'final' => memory_get_usage(),
        'real' => memory_get_usage(true),
    ],
    'time' => [
        'revs' => $revolutions,
        'net' => (int) $time,
    ],
    'buffer' => $buffer,
]);

exit(0);
