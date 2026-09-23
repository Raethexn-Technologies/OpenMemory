<?php

return [
    // A configured credential is not permission to transmit user content.
    'model_operations' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MODEL_DISCLOSURE_OPERATIONS', '')),
    ))),
    'max_input_bytes' => 64000,
    'ingest_publication' => (bool) env('ALLOW_INGEST_PUBLICATION', false),
];
