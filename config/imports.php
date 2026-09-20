<?php

return [
    'disk' => env('IMPORTS_DISK', 'production_imports'),
    'max_upload_kilobytes' => (int) env('IMPORTS_MAX_UPLOAD_KB', 25 * 1024),
    'default_chunk_size' => (int) env('IMPORTS_CHUNK_SIZE', 250),
    'preview_rows' => (int) env('IMPORTS_PREVIEW_ROWS', 100),
    'max_rows' => (int) env('IMPORTS_MAX_ROWS', 100000),
];
