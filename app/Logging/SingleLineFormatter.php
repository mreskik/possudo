<?php

namespace App\Logging;

use Monolog\Formatter\LineFormatter;

// SingleLineFormatter: dipasang lewat 'tap' di config/logging.php channel 'jobs'. Force TIAP
// entry log jadi 1 baris utuh (allowInlineLineBreaks=false, includeStacktraces=false) -- default
// Laravel bisa multi-line kalau ada stack trace/context kompleks, bikin susah di-grep/tail buat
// nyari 1 kejadian spesifik. Dipakai khusus buat log background job (App\Console\Commands),
// lihat DOKUMENTASI BACKGROUND JOB/POLA UMUM.md.
class SingleLineFormatter
{
    public function __invoke($logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            $handler->setFormatter(new LineFormatter(
                format: null,
                dateFormat: null,
                allowInlineLineBreaks: false,
                ignoreEmptyContextAndExtra: true,
                includeStacktraces: false,
            ));
        }
    }
}
