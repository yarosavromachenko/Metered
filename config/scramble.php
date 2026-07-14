<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| OpenAPI generation
|--------------------------------------------------------------------------
|
| The document is generated from the routes, the validation rules and the
| responses the controllers build, by `make openapi`, and published by CI as
| a build artifact rather than committed: a generated file in the repository
| is one more thing that can quietly disagree with the code. docs/api.md
| explains the rules the schema cannot express — what is checked before 202
| and what after, what deduplication promises.
|
| Only the keys that differ from the package's defaults are set here; the
| rest are merged in from it. The package is a development dependency, so in
| the production image this file is read by nothing.
|
*/

return [
    'api_path' => 'api',

    'export_path' => 'docs/api/openapi.json',

    'info' => [
        'version' => '1',
        'description' => 'Usage-based billing. Every endpoint is authenticated with a project API key, '
            . 'sent as `Authorization: Bearer mk_<env>_<prefix>_<secret>`. The rules behind the '
            . 'schema — deduplication, the acceptance window, rejections, backpressure — are in '
            . 'docs/api.md.',
    ],
];
