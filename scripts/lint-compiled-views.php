<?php

require dirname(__DIR__).'/vendor/autoload.php';

use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

$finder = (new Finder)->files()->name('*.php')->in(dirname(__DIR__).'/storage/framework/views');
$failed = [];
foreach ($finder as $file) {
    $process = new Process([PHP_BINARY, '-l', $file->getRealPath()]);
    $process->run();
    if (! $process->isSuccessful()) {
        $failed[] = $file->getFilename().': '.trim($process->getErrorOutput().$process->getOutput());
    }
}
if ($failed !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failed).PHP_EOL);
    exit(1);
}
fwrite(STDOUT, 'Compiled Blade PHP syntax passed for '.$finder->count().' files.'.PHP_EOL);
